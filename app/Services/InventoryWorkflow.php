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

final class InventoryWorkflow
{
    private const CODE = 'regex:/\A[A-Z0-9][A-Z0-9._-]{0,63}\z/';

    private const DEFINITIONS = [
        'items' => [
            'sku' => ['required', 'string', self::CODE],
            'name' => ['required', 'string', 'max:160'],
            'base_unit' => ['required', 'string', 'regex:/\A[a-z][a-z0-9_-]{0,31}\z/'],
        ],
        'locations' => [
            'code' => ['required', 'string', self::CODE],
            'name' => ['required', 'string', 'max:160'],
        ],
        'lots' => [
            'item_id' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', self::CODE],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:0001-01-01'],
        ],
    ];

    public function __construct(private readonly TenantContext $tenant, private readonly AuditLogService $audit) {}

    public function definitions(string $resource, array $input, User $actor): LengthAwarePaginator
    {
        $this->authorize($actor, 'view');
        $columns = array_keys(self::DEFINITIONS[$resource]);
        $query = $this->query($resource)->select(['id', ...$columns])->orderBy('id');
        $filters = $resource === 'lots' ? ['item_id'] : [];

        return $this->page($query, $input, $filters);
    }

    public function createDefinition(string $resource, array $input, User $actor): stdClass
    {
        $this->authorize($actor, 'manage_catalog');
        $rules = self::DEFINITIONS[$resource];
        $data = $this->validate($input, $rules);

        try {
            return DB::transaction(function () use ($resource, $data, $rules, $actor) {
                if ($resource === 'lots') {
                    $this->find($this->query('items'), (int) $data['item_id']);
                }
                $id = DB::table('inventory_'.$resource)->insertGetId([
                    ...$data, 'tenant_id' => $this->tenant->requireId(),
                ]);
                $this->audit->record('inventory.created', 'inventory_'.$resource, $id, $actor->id);

                return $this->find($this->query($resource)->select(['id', ...array_keys($rules)]), $id);
            }, 3);
        } catch (QueryException $exception) {
            abort_if((string) $exception->getCode() === '23505', 409, 'This inventory code is already in use.');
            throw $exception;
        }
    }

    public function movements(array $input, User $actor): LengthAwarePaginator
    {
        $this->authorize($actor, 'view');

        return $this->page($this->query('movements')->orderByDesc('id'), $input, ['item_id', 'location_id', 'lot_id'])
            ->through(fn (stdClass $row) => $this->movementData($row));
    }

    public function balances(array $input, User $actor): LengthAwarePaginator
    {
        $this->authorize($actor, 'view');
        $query = $this->query('movements')->select('item_id', 'location_id', 'lot_id')
            ->selectRaw('SUM(quantity_delta)::text AS quantity')
            ->groupBy('item_id', 'location_id', 'lot_id')
            ->orderBy('item_id')->orderBy('location_id')->orderBy('lot_id');

        return $this->page($query, $input, ['item_id', 'location_id', 'lot_id']);
    }

    public function record(array $input, string $key, User $actor, ?int $originalId = null): array
    {
        $this->authorize($actor, $originalId === null ? 'record_movements' : 'correct_movements');
        Validator::make(['idempotency_key' => $key], ['idempotency_key' => ['required', 'uuid']])->validate();
        $rules = ['reason' => ['required', 'string', 'max:500']];
        if ($originalId === null) {
            $rules += [
                'item_id' => ['required', 'integer', 'min:1'],
                'location_id' => ['required', 'integer', 'min:1'],
                'lot_id' => ['nullable', 'integer', 'min:1'],
                'kind' => ['required', 'in:receipt,issue,adjustment'],
                'quantity_delta' => ['required', 'string', 'regex:/\A-?(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?\z/'],
            ];
        }
        $data = $this->validate($input, $rules);

        try {
            return DB::transaction(function () use ($data, $key, $actor, $originalId) {
                $original = $originalId === null ? null : $this->find($this->query('movements'), $originalId);
                $item = $this->find($this->query('items')->lockForUpdate(), (int) ($original->item_id ?? $data['item_id']));
                $location = $this->find($this->query('locations'), (int) ($original->location_id ?? $data['location_id']));
                $lotId = $original === null ? ($data['lot_id'] ?? null) : $original->lot_id;
                $lot = $lotId === null ? null : $this->find($this->query('lots')->where('item_id', $item->id), (int) $lotId);
                $kind = $original === null ? $data['kind'] : 'correction';
                $quantity = $original === null
                    ? $this->normalizeDelta($data['quantity_delta'], $kind)
                    : $this->inverse($original->quantity_delta);
                $payload = [
                    'location_id' => $location->id,
                    'lot_id' => $lot?->id,
                    'kind' => $kind,
                    'quantity_delta' => $quantity,
                    'reason' => trim($data['reason']),
                    'corrects_movement_id' => $originalId,
                ];
                if ($payload['reason'] === '') {
                    throw ValidationException::withMessages(['reason' => ['Provide a reason for this movement.']]);
                }
                $hash = hash('sha256', serialize($payload));
                $existing = $this->query('movements')->where('item_id', $item->id)->where('idempotency_key', $key)->first();
                if ($existing !== null) {
                    abort_unless($existing->request_hash === $hash && (int) $existing->recorded_by_user_id === $actor->id, 409, 'Idempotency key already used.');

                    return ['movement' => $this->movementData($existing), 'replayed' => true];
                }
                $id = DB::table('inventory_movements')->insertGetId([
                    ...$payload,
                    'tenant_id' => $this->tenant->requireId(),
                    'item_id' => $item->id,
                    'recorded_by_user_id' => $actor->id,
                    'idempotency_key' => $key,
                    'request_hash' => $hash,
                ]);
                $this->audit->record('inventory.'.$kind, 'inventory_movements', $id, $actor->id);

                return ['movement' => $this->movementData($this->find($this->query('movements'), $id)), 'replayed' => false];
            }, 3);
        } catch (QueryException $exception) {
            abort_if((string) $exception->getCode() === 'P5101', 409, 'Insufficient stock in this location and lot.');
            abort_if((string) $exception->getCode() === 'P5102', 409, 'This movement cannot be corrected.');
            throw $exception;
        }
    }

    private function normalizeDelta(string $value, string $kind): string
    {
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $amount = $whole.'.'.str_pad($fraction, 6, '0');
        if ($amount === '0.000000' || ($kind === 'issue' && ! $negative) || ($kind === 'receipt' && $negative)) {
            throw ValidationException::withMessages(['quantity_delta' => ['Use a nonzero quantity with the sign required for this movement.']]);
        }

        return $negative ? '-'.$amount : $amount;
    }

    private function inverse(string $quantity): string
    {
        $value = str_starts_with($quantity, '-') ? substr($quantity, 1) : '-'.$quantity;

        return $this->normalizeDelta($value, 'correction');
    }

    private function movementData(stdClass $row): array
    {
        $data = (array) $row;
        unset($data['request_hash'], $data['idempotency_key'], $data['tenant_id']);
        $data['recorded_at'] = Carbon::parse($row->recorded_at)->toISOString();

        return $data;
    }

    private function page(Builder $query, array $input, array $filters): LengthAwarePaginator
    {
        $rules = ['page' => ['sometimes', 'integer', 'min:1', 'max:1000000'], 'per_page' => ['sometimes', 'integer', 'between:1,100']];
        foreach ($filters as $column) {
            $rules[$column] = ['sometimes', 'integer', 'min:1'];
        }
        $data = $this->validate($input, $rules);
        foreach ($filters as $column) {
            if (isset($data[$column])) {
                $query->where($column, $data[$column]);
            }
        }

        return $query->paginate((int) ($data['per_page'] ?? 15), page: (int) ($data['page'] ?? 1));
    }

    private function query(string $resource): Builder
    {
        return DB::table('inventory_'.$resource)->where('tenant_id', $this->tenant->requireId());
    }

    private function find(Builder $query, int $id): stdClass
    {
        $row = $query->where('id', $id)->first();
        abort_if($row === null, 404, 'Inventory record not found.');

        return $row;
    }

    private function authorize(User $actor, string $action): void
    {
        $this->tenant->requireId();
        abort_unless($actor->isMemberOfCurrentTenant() && $actor->hasPermission('inventory.'.$action), 403);
    }

    private function validate(array $input, array $rules): array
    {
        $unknown = array_diff(array_keys($input), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages([reset($unknown) => ['Unsupported inventory field.']]);
        }

        return Validator::make($input, $rules)->validate();
    }
}
