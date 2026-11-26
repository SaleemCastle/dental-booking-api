<?php

namespace App\Services;

use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use stdClass;

final class SupplierInvoiceRegister
{
    private const TABLE = 'procurement_supplier_invoices';

    public function __construct(private readonly TenantContext $tenant, private readonly AuditLogService $audit) {}

    public function index(array $input, User $actor): LengthAwarePaginator
    {
        $this->authorize($actor, 'view');
        $data = $this->validate($input, [
            'supplier_id' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'between:1,1000000'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $query = $this->query(self::TABLE)->orderByDesc('id');
        if (isset($data['supplier_id'])) {
            $query->where('supplier_id', $data['supplier_id']);
        }

        return $query->paginate((int) ($data['per_page'] ?? 15), page: (int) ($data['page'] ?? 1))
            ->through(fn (stdClass $invoice) => $this->present($invoice));
    }

    public function show(int $id, User $actor): array
    {
        $this->authorize($actor, 'view');
        $invoice = $this->query(self::TABLE)->where('id', $id)->first();
        abort_if($invoice === null, 404, 'Supplier invoice not found.');

        return $this->present($invoice);
    }

    public function record(array $input, string $key, User $actor): array
    {
        $this->authorize($actor, 'record');
        Validator::make(['idempotency_key' => $key], ['idempotency_key' => ['required', 'uuid']])->validate();
        $data = $this->validate($input, [
            'supplier_id' => ['required', 'integer', 'min:1'],
            'supplier_receipt_id' => ['nullable', 'integer', 'min:1'],
            'invoice_number' => ['required', 'string', 'regex:~\A[A-Za-z0-9][ A-Za-z0-9._/#-]{0,79}\z~'],
            'invoice_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:0001-01-01'],
            'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:0001-01-01'],
            'currency' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/'],
            'reported_total' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,2})?\z/'],
        ]);
        [$whole, $fraction] = array_pad(explode('.', $data['reported_total'], 2), 2, '');
        $payload = [
            'supplier_id' => (int) $data['supplier_id'],
            'supplier_receipt_id' => isset($data['supplier_receipt_id']) ? (int) $data['supplier_receipt_id'] : null,
            'invoice_number' => rtrim($data['invoice_number']),
            'invoice_date' => $data['invoice_date'],
            'due_on' => $data['due_on'] ?? null,
            'currency' => $data['currency'],
            'reported_total' => $whole.'.'.str_pad($fraction, 2, '0'),
        ];
        $hash = hash('sha256', serialize($payload));

        return DB::transaction(function () use ($payload, $actor, $hash, $key) {
            $command = $this->query(self::TABLE)->where('idempotency_key', $key);
            if ($existing = $command->first()) {
                return $this->replay($existing, $actor, $hash);
            }
            $this->assertReferences($payload['supplier_id'], $payload['supplier_receipt_id']);
            $inserted = DB::table(self::TABLE)->insertOrIgnore([
                ...$payload,
                'tenant_id' => $this->tenant->requireId(),
                'recorded_by_user_id' => $actor->id,
                'idempotency_key' => $key,
                'request_hash' => $hash,
            ]);
            $invoice = $command->first();
            abort_if($invoice === null, 409, 'Supplier invoice number already recorded.');
            if ($inserted === 0) {
                return $this->replay($invoice, $actor, $hash);
            }
            $this->audit->record('procurement.supplier_invoice_recorded', self::TABLE, $invoice->id, $actor->id);

            return ['invoice' => $this->present($invoice), 'replayed' => false];
        }, 3);
    }

    private function assertReferences(int $supplier, ?int $receipt): void
    {
        abort_unless($this->query('procurement_suppliers')->where('id', $supplier)->exists(), 404, 'Supplier not found.');
        if ($receipt !== null) {
            abort_unless($this->query('procurement_supplier_receipts')->where('id', $receipt)
                ->where('supplier_id', $supplier)->exists(), 404, 'Receipt not found for this supplier.');
        }
    }

    private function replay(stdClass $invoice, User $actor, string $hash): array
    {
        abort_unless($invoice->request_hash === $hash && $invoice->recorded_by_user_id === $actor->id, 409, 'Idempotency key already used.');

        return ['invoice' => $this->present($invoice), 'replayed' => true];
    }

    private function present(stdClass $invoice): array
    {
        $data = (array) $invoice;
        unset($data['tenant_id'], $data['idempotency_key'], $data['request_hash']);
        $data['recorded_at'] = Carbon::parse($invoice->recorded_at)->toISOString();

        return $data;
    }

    private function authorize(User $actor, string $action): void
    {
        $this->tenant->requireId();
        abort_unless($actor->isMemberOfCurrentTenant() && $actor->hasPermission('supplier_invoices.'.$action), 403);
    }

    private function query(string $table): Builder
    {
        return DB::table($table)->where('tenant_id', $this->tenant->requireId());
    }

    private function validate(array $input, array $rules): array
    {
        $unknown = array_diff(array_keys($input), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages([reset($unknown) => ['Unsupported supplier invoice field.']]);
        }

        return Validator::make($input, $rules)->validate();
    }
}
