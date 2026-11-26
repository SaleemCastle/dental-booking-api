<?php

namespace App\Services;

use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

final class SupplierReceivingWorkflow
{
    private const RECEIPTS = 'procurement_supplier_receipts';

    public function __construct(private readonly TenantContext $tenant, private readonly AuditLogService $audit) {}

    public function index(array $input, User $actor): LengthAwarePaginator
    {
        $this->authorize($actor, 'view');
        $data = $this->validate($input, [
            'supplier_id' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'between:1,1000000'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $query = $this->query(self::RECEIPTS)->orderByDesc('id');
        if (isset($data['supplier_id'])) {
            $query->where('supplier_id', $data['supplier_id']);
        }

        return $query->paginate((int) ($data['per_page'] ?? 15), page: (int) ($data['page'] ?? 1))
            ->through(fn (stdClass $receipt) => $this->present($receipt));
    }

    public function show(int $id, User $actor): array
    {
        $this->authorize($actor, 'view');
        $receipt = $this->query(self::RECEIPTS)->where('id', $id)->first();
        abort_if($receipt === null, 404, 'Supplier receipt not found.');

        return $this->present($receipt, withLines: true);
    }

    public function record(array $input, string $key, User $actor): array
    {
        $this->authorize($actor, 'record');
        Validator::make(['idempotency_key' => $key], ['idempotency_key' => ['required', 'uuid']])->validate();
        $data = $this->validate($input, [
            'supplier_id' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'regex:~\A[A-Z0-9][A-Z0-9._/-]{0,79}\z~'],
            'received_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:0001-01-01', 'before_or_equal:'.today('UTC')->toDateString()],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*' => ['required', 'array:item_id,location_id,lot_id,quantity'],
            'lines.*.item_id' => ['required', 'integer', 'min:1'],
            'lines.*.location_id' => ['required', 'integer', 'min:1'],
            'lines.*.lot_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?\z/'],
        ]);
        $supplierId = (int) $data['supplier_id'];
        $lines = $this->normalizeLines($data['lines']);
        $hash = hash('sha256', serialize([$supplierId, $data['reference'], $data['received_on'], $lines]));

        return DB::transaction(function () use ($actor, $key, $supplierId, $data, $lines, $hash) {
            $command = $this->query(self::RECEIPTS)->where('idempotency_key', $key);
            if ($existing = $command->first()) {
                return $this->replay($existing, $actor, $hash);
            }
            $supplier = $this->query('procurement_suppliers')->where('id', $supplierId)->lockForUpdate()->first();
            abort_if($supplier === null, 404, 'Supplier not found.');
            if ($existing = $command->first()) {
                return $this->replay($existing, $actor, $hash);
            }
            abort_unless($supplier->active, 409, 'Choose an active supplier.');
            $this->assertStockReferences($lines);

            $inserted = DB::table(self::RECEIPTS)->insertOrIgnore([
                'tenant_id' => $this->tenant->requireId(), 'supplier_id' => $supplierId,
                'reference' => $data['reference'], 'received_on' => $data['received_on'],
                'recorded_by_user_id' => $actor->id, 'idempotency_key' => $key, 'request_hash' => $hash,
            ]);
            $receipt = $command->first();
            abort_if($receipt === null, 409, 'Supplier receipt reference already used.');
            if ($inserted === 0) {
                return $this->replay($receipt, $actor, $hash);
            }

            foreach ($lines as $line) {
                $id = DB::table('inventory_movements')->insertGetId([
                    ...$line, 'tenant_id' => $this->tenant->requireId(), 'supplier_receipt_id' => $receipt->id,
                    'kind' => 'receipt', 'reason' => 'Supplier goods receipt', 'recorded_by_user_id' => $actor->id,
                    'idempotency_key' => (string) Str::uuid(), 'request_hash' => $hash,
                ]);
                $this->audit->record('inventory.receipt', 'inventory_movements', $id, $actor->id);
            }
            $this->audit->record('procurement.supplier_receipt_recorded', self::RECEIPTS, $receipt->id, $actor->id);

            return ['receipt' => $this->present($receipt, withLines: true), 'replayed' => false];
        }, 3);
    }

    private function normalizeLines(array $input): array
    {
        if (! array_is_list($input)) {
            throw ValidationException::withMessages(['lines' => ['Use a list of receipt lines.']]);
        }
        $lines = [];
        $seen = [];
        foreach ($input as $index => $row) {
            $item = (int) $row['item_id'];
            $location = (int) $row['location_id'];
            $lot = isset($row['lot_id']) ? (int) $row['lot_id'] : null;
            [$whole, $fraction] = array_pad(explode('.', $row['quantity'], 2), 2, '');
            $quantity = $whole.'.'.str_pad($fraction, 6, '0');
            if ($quantity === '0.000000') {
                throw ValidationException::withMessages(["lines.$index.quantity" => ['Quantity must be positive.']]);
            }
            $bucket = "$item:$location:".$lot;
            if (isset($seen[$bucket])) {
                throw ValidationException::withMessages(["lines.$index" => ['Use one line per item, location and lot.']]);
            }
            $seen[$bucket] = true;
            $lines[] = ['item_id' => $item, 'location_id' => $location, 'lot_id' => $lot, 'quantity_delta' => $quantity];
        }
        // The stock ledger locks item rows. Acquire them in the same order for every batch.
        usort($lines, fn ($left, $right) => [$left['item_id'], $left['location_id'], $left['lot_id']]
            <=> [$right['item_id'], $right['location_id'], $right['lot_id']]);

        return $lines;
    }

    private function assertStockReferences(array $lines): void
    {
        foreach (['item_id' => 'inventory_items', 'location_id' => 'inventory_locations'] as $column => $table) {
            $ids = array_unique(array_column($lines, $column));
            abort_unless($this->query($table)->whereIn('id', $ids)->count() === count($ids), 404, 'Stock reference not found.');
        }
        $lotIds = array_filter(array_unique(array_column($lines, 'lot_id')));
        $lotItems = $this->query('inventory_lots')->whereIn('id', $lotIds)->pluck('item_id', 'id');
        foreach ($lines as $line) {
            abort_if($line['lot_id'] !== null && $lotItems->get($line['lot_id']) !== $line['item_id'], 404, 'Stock lot not found for this item.');
        }
    }

    private function replay(stdClass $receipt, User $actor, string $hash): array
    {
        abort_unless($receipt->request_hash === $hash && $receipt->recorded_by_user_id === $actor->id, 409, 'Idempotency key already used.');

        return ['receipt' => $this->present($receipt, withLines: true), 'replayed' => true];
    }

    private function present(stdClass $receipt, bool $withLines = false): array
    {
        $data = (array) $receipt;
        unset($data['tenant_id'], $data['idempotency_key'], $data['request_hash'], $data['creation_transaction']);
        $data['recorded_at'] = Carbon::parse($receipt->recorded_at)->toISOString();
        if ($withLines) {
            $data['lines'] = DB::table('inventory_movements as movements')
                ->leftJoin('inventory_lots as lots', function ($join) {
                    $join->on('lots.tenant_id', '=', 'movements.tenant_id')
                        ->on('lots.item_id', '=', 'movements.item_id')
                        ->on('lots.id', '=', 'movements.lot_id');
                })
                ->where('movements.tenant_id', $this->tenant->requireId())
                ->where('movements.supplier_receipt_id', $receipt->id)
                ->orderBy('movements.id')
                ->get(['movements.id as movement_id', 'movements.item_id', 'movements.location_id',
                    'movements.lot_id', 'movements.quantity_delta as quantity', 'lots.code as lot_code', 'lots.expires_on'])
                ->all();
        }

        return $data;
    }

    private function authorize(User $actor, string $action): void
    {
        $this->tenant->requireId();
        abort_unless($actor->isMemberOfCurrentTenant() && $actor->hasPermission('supplier_receipts.'.$action), 403);
    }

    private function query(string $table): Builder
    {
        return DB::table($table)->where('tenant_id', $this->tenant->requireId());
    }

    private function validate(array $input, array $rules): array
    {
        $unknown = array_diff(array_keys($input), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages([reset($unknown) => ['Unsupported receiving field.']]);
        }

        return Validator::make($input, $rules)->validate();
    }
}
