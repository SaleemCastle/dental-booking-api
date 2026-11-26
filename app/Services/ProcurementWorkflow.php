<?php

namespace App\Services;

use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use stdClass;

final class ProcurementWorkflow
{
    private const SUPPLIER_FIELDS = [
        'name' => ['required', 'string', 'max:160'],
        'email' => ['nullable', 'email:rfc', 'max:254'],
        'phone' => ['nullable', 'string', 'max:40'],
    ];

    private const DRAFT_FIELDS = [
        'notes' => ['nullable', 'string', 'max:1000'],
        'lines' => ['required', 'array', 'min:1', 'max:100'],
        'lines.*' => ['required', 'array:item_id,quantity'],
        'lines.*.item_id' => ['required', 'integer', 'min:1', 'distinct'],
        'lines.*.quantity' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?\z/'],
    ];

    public function __construct(private readonly TenantContext $tenant, private readonly AuditLogService $audit) {}

    public function suppliers(array $input, User $actor): LengthAwarePaginator
    {
        $this->authorize($actor, 'suppliers.view');

        return $this->page($this->query('procurement_suppliers')->select('id', 'code', 'name', 'email', 'phone', 'active'), $input);
    }

    public function saveSupplier(array $input, User $actor, ?int $id = null): stdClass
    {
        $this->authorize($actor, 'suppliers.manage');
        $rules = self::SUPPLIER_FIELDS;
        if ($id === null) {
            $rules['code'] = ['required', 'string', 'regex:/\A[A-Z0-9][A-Z0-9._-]{0,63}\z/'];
        } else {
            $rules['name'][] = 'sometimes';
            $rules['active'] = ['sometimes', 'required', 'boolean'];
        }
        $data = $this->validate($input, $rules);
        abort_if($data === [], 422, 'Supply at least one supplier field.');

        try {
            return DB::transaction(function () use ($data, $id, $actor) {
                if ($id === null) {
                    $id = DB::table('procurement_suppliers')->insertGetId([...$data, 'tenant_id' => $this->tenant->requireId()]);
                    $this->audit->record('procurement.supplier_created', 'procurement_suppliers', $id, $actor->id);
                } else {
                    $query = $this->query('procurement_suppliers')->where('id', $id);
                    $this->find($query->clone()->lockForUpdate());
                    $query->update($data);
                    $this->audit->record('procurement.supplier_updated', 'procurement_suppliers', $id, $actor->id);
                }

                return $this->find($this->query('procurement_suppliers')->where('id', $id)->select('id', 'code', 'name', 'email', 'phone', 'active'));
            }, 3);
        } catch (QueryException $exception) {
            abort_if((string) $exception->getCode() === '23505', 409, 'Supplier code is already in use.');
            throw $exception;
        }
    }

    public function orders(array $input, User $actor): LengthAwarePaginator
    {
        $this->authorize($actor, 'purchase_orders.view');

        return $this->page($this->query('procurement_orders'), $input, ['supplier_id' => ['sometimes', 'integer', 'min:1']])
            ->through(fn (stdClass $order) => $this->present($order));
    }

    public function order(int $id, User $actor): array
    {
        $this->authorize($actor, 'purchase_orders.view');

        return DB::transaction(function () use ($id) {
            return $this->present($this->find($this->query('procurement_orders')->where('id', $id)->sharedLock()), withLines: true);
        }, 3);
    }

    public function createOrder(array $input, string $key, User $actor): array
    {
        $this->authorize($actor, 'purchase_orders.manage');
        Validator::make(['idempotency_key' => $key], ['idempotency_key' => ['required', 'uuid']])->validate();
        $data = $this->validate($input, self::DRAFT_FIELDS + ['supplier_id' => ['required', 'integer', 'min:1']]);
        $lines = $this->normalizeLines($data['lines']);
        $supplierId = (int) $data['supplier_id'];
        $notes = $data['notes'] ?? null;
        $hash = hash('sha256', serialize([$supplierId, $notes, $lines]));

        return DB::transaction(function () use ($actor, $key, $supplierId, $notes, $lines, $hash) {
            $query = $this->query('procurement_orders')->where('idempotency_key', $key);
            if ($order = $query->clone()->lockForUpdate()->first()) {
                return $this->replay($order, $actor, $hash);
            }
            $supplier = $this->find($this->query('procurement_suppliers')->where('id', $supplierId)->lockForUpdate());
            if ($order = $query->clone()->lockForUpdate()->first()) {
                return $this->replay($order, $actor, $hash);
            }
            abort_unless($supplier->active, 409, 'Choose an active supplier.');
            $this->assertItemsExist($lines);

            // A concurrent command for another supplier can use the same clinic key.
            $inserted = DB::table('procurement_orders')->insertOrIgnore([
                'tenant_id' => $this->tenant->requireId(), 'supplier_id' => $supplierId, 'notes' => $notes,
                'created_by_user_id' => $actor->id, 'idempotency_key' => $key, 'request_hash' => $hash,
            ]);
            $order = $this->find($query->lockForUpdate());
            if ($inserted === 0) {
                return $this->replay($order, $actor, $hash);
            }
            $this->writeLines($order->id, $lines);
            $this->audit->record('procurement.draft_created', 'procurement_orders', $order->id, $actor->id);

            return ['order' => $this->present($order, withLines: true), 'replayed' => false];
        }, 3);
    }

    public function replaceOrder(int $id, array $input, User $actor): array
    {
        $this->authorize($actor, 'purchase_orders.manage');
        $data = $this->validate($input, self::DRAFT_FIELDS + ['version' => ['required', 'integer', 'between:1,2147483646']]);
        $lines = $this->normalizeLines($data['lines']);

        return DB::transaction(function () use ($data, $lines, $id, $actor) {
            $query = $this->query('procurement_orders')->where('id', $id);
            $order = $this->find($query->clone()->lockForUpdate());
            abort_unless($order->version === (int) $data['version'], 409, 'The draft version has changed.');
            $this->assertItemsExist($lines);
            $this->query('procurement_order_lines')->where('order_id', $id)->delete();
            $this->writeLines($id, $lines);
            $query->update(['notes' => $data['notes'] ?? null, 'version' => $order->version + 1]);
            $this->audit->record('procurement.draft_replaced', 'procurement_orders', $id, $actor->id);

            return $this->present($this->find($query), withLines: true);
        }, 3);
    }

    private function normalizeLines(array $input): array
    {
        if (! array_is_list($input)) {
            throw ValidationException::withMessages(['lines' => ['Use a list of order lines.']]);
        }
        $lines = [];
        foreach ($input as $index => $row) {
            [$whole, $fraction] = array_pad(explode('.', $row['quantity'], 2), 2, '');
            $quantity = $whole.'.'.str_pad($fraction, 6, '0');
            if ($quantity === '0.000000') {
                throw ValidationException::withMessages(["lines.$index.quantity" => ['Quantity must be positive.']]);
            }
            $lines[(int) $row['item_id']] = $quantity;
        }
        ksort($lines, SORT_NUMERIC);

        return $lines;
    }

    private function assertItemsExist(array $lines): void
    {
        abort_unless($this->query('inventory_items')->whereIn('id', array_keys($lines))->count() === count($lines), 404, 'Inventory item not found.');
    }

    private function writeLines(int $id, array $lines): void
    {
        $rows = [];
        foreach ($lines as $item => $quantity) {
            $rows[] = ['tenant_id' => $this->tenant->requireId(), 'order_id' => $id, 'item_id' => $item, 'quantity' => $quantity];
        }
        DB::table('procurement_order_lines')->insert($rows);
    }

    private function replay(stdClass $order, User $actor, string $hash): array
    {
        abort_unless($order->request_hash === $hash && $order->created_by_user_id === $actor->id, 409, 'Idempotency key already used.');

        return ['order' => $this->present($order, withLines: true), 'replayed' => true];
    }

    private function present(stdClass $order, bool $withLines = false): array
    {
        $data = (array) $order;
        unset($data['tenant_id'], $data['idempotency_key'], $data['request_hash']);
        $data['created_at'] = Carbon::parse($order->created_at)->toISOString();
        if ($withLines) {
            $data['lines'] = $this->query('procurement_order_lines')->where('order_id', $order->id)
                ->select('item_id', 'quantity')->orderBy('item_id')->get()->all();
        }

        return $data;
    }

    private function authorize(User $actor, string $permission): void
    {
        $this->tenant->requireId();
        abort_unless($actor->isMemberOfCurrentTenant() && $actor->hasPermission($permission), 403);
    }

    private function query(string $table): Builder
    {
        return DB::table($table)->where('tenant_id', $this->tenant->requireId());
    }

    private function find(Builder $query): stdClass
    {
        $row = $query->first();
        abort_if($row === null, 404, 'Procurement record not found.');

        return $row;
    }

    private function page(Builder $query, array $input, array $filters = []): LengthAwarePaginator
    {
        $data = $this->validate($input, $filters + [
            'page' => ['sometimes', 'integer', 'between:1,1000000'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        if (isset($data['supplier_id'])) {
            $query->where('supplier_id', $data['supplier_id']);
        }

        return $query->orderBy('id')->paginate((int) ($data['per_page'] ?? 15), page: (int) ($data['page'] ?? 1));
    }

    private function validate(array $input, array $rules): array
    {
        $unknown = array_diff(array_keys($input), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages([reset($unknown) => ['Unsupported procurement field.']]);
        }

        return Validator::make($input, $rules)->validate();
    }
}
