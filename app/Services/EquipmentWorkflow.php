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

final class EquipmentWorkflow
{
    private const ASSETS = 'equipment_assets';

    private const RECORDS = 'equipment_records';

    private const STATUS = 'in:active,out_of_service,retired';

    private const ASSET_FIELDS = [
        'name' => ['required', 'string', 'max:160'],
        'serial_number' => ['nullable', 'string', 'max:120'],
        'location_label' => ['nullable', 'string', 'max:160'],
    ];

    private const DATE = ['date_format:Y-m-d', 'after_or_equal:0001-01-01'];

    public function __construct(private readonly TenantContext $tenant, private readonly AuditLogService $audit) {}

    public function index(array $input, User $actor): LengthAwarePaginator
    {
        $this->authorize($actor, 'view');
        $data = $this->validate($input, $this->pageRules() + ['status' => ['sometimes', self::STATUS]]);
        $query = $this->query(self::ASSETS)->orderBy('id');
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }

        return $this->page($query, $data);
    }

    public function show(int $id, User $actor): array
    {
        $this->authorize($actor, 'view');

        return $this->present($this->findAsset($this->asset($id)));
    }

    public function create(array $input, User $actor): array
    {
        $this->authorize($actor, 'manage');
        $data = $this->validate($input, self::ASSET_FIELDS + ['asset_tag' => ['required', 'string', 'regex:/\A[A-Z0-9][A-Z0-9._-]{0,63}\z/']]);

        try {
            return DB::transaction(function () use ($data, $actor) {
                $id = DB::table(self::ASSETS)->insertGetId([
                    ...$data, 'tenant_id' => $this->tenant->requireId(), 'recorded_by_user_id' => $actor->id,
                ]);
                $this->audit->record('equipment.registered', self::ASSETS, $id, $actor->id);

                return $this->present($this->findAsset($this->asset($id)));
            }, 3);
        } catch (QueryException $exception) {
            abort_if((string) $exception->getCode() === '23505', 409, 'Equipment tag already used.');
            throw $exception;
        }
    }

    public function update(int $id, array $input, User $actor): array
    {
        $this->authorize($actor, 'manage');
        $rules = self::ASSET_FIELDS;
        $rules['name'][] = 'sometimes';
        $data = $this->validate($input, $rules + [
            'status' => ['sometimes', 'required', self::STATUS],
            'version' => ['required', 'integer', 'between:1,2147483646'],
        ]);
        $version = (int) $data['version'];
        unset($data['version']);
        if ($data === []) {
            throw ValidationException::withMessages(['equipment' => ['Supply at least one field to change.']]);
        }

        return DB::transaction(function () use ($id, $version, $data, $actor) {
            $query = $this->asset($id);
            $asset = $this->findAsset($query->clone()->lockForUpdate());
            abort_unless($asset->version === $version, 409, 'Equipment version has changed.');
            $status = $data['status'] ?? $asset->status;
            abort_if($asset->status === 'retired' && $status !== 'retired', 409, 'Retired equipment cannot return to service.');
            $query->update([...$data, 'version' => $version + 1]);
            $action = $asset->status === $status ? 'equipment.updated' : 'equipment.status_'.$status;
            $this->audit->record($action, self::ASSETS, $id, $actor->id);

            return $this->present($this->findAsset($query));
        }, 3);
    }

    public function history(int $id, string $kind, array $input, User $actor): LengthAwarePaginator
    {
        $this->authorize($actor, 'view');
        $data = $this->validate($input, $this->pageRules());
        $this->findAsset($this->asset($id));

        return $this->page($this->query(self::RECORDS)->where('equipment_id', $id)->where('kind', $kind)->orderByDesc('id'), $data);
    }

    public function record(int $id, string $kind, array $input, string $key, User $actor): array
    {
        $this->authorize($actor, 'record_history');
        Validator::make(['idempotency_key' => $key], ['idempotency_key' => ['required', 'uuid']])->validate();
        $rules = $kind === 'maintenance'
            ? [
                'performed_on' => ['required', ...self::DATE, 'before_or_equal:'.today('UTC')->toDateString()],
                'next_due_on' => ['nullable', ...self::DATE, 'after_or_equal:performed_on'],
                'provider' => ['nullable', 'string', 'max:160'],
                'details' => ['required', 'string', 'max:1000'],
            ]
            : [
                'starts_on' => ['required', ...self::DATE],
                'ends_on' => ['required', ...self::DATE, 'after_or_equal:starts_on'],
                'provider' => ['required', 'string', 'max:160'],
                'details' => ['nullable', 'string', 'max:1000'],
            ];
        $rules['reference'] = ['nullable', 'string', 'max:120'];
        $validated = $this->validate($input, $rules);
        $data = [];
        foreach (array_keys($rules) as $field) {
            $data[$field] = $validated[$field] ?? null;
        }
        $hash = hash('sha256', serialize([$id, $kind, $data]));

        return DB::transaction(function () use ($id, $kind, $key, $actor, $data, $hash) {
            $this->findAsset($this->asset($id));
            $inserted = DB::table(self::RECORDS)->insertOrIgnore([
                ...$data, 'tenant_id' => $this->tenant->requireId(), 'equipment_id' => $id, 'kind' => $kind,
                'recorded_by_user_id' => $actor->id, 'idempotency_key' => $key, 'request_hash' => $hash,
            ]);
            $record = $this->query(self::RECORDS)->where('idempotency_key', $key)->first();
            abort_if($record === null || $record->request_hash !== $hash || $record->recorded_by_user_id !== $actor->id, 409, 'Idempotency key already used.');
            if ($inserted === 1) {
                $this->audit->record('equipment.'.$kind.'_recorded', self::RECORDS, $record->id, $actor->id);
            }

            return ['record' => $this->present($record), 'replayed' => $inserted === 0];
        }, 3);
    }

    private function present(stdClass $row): array
    {
        $data = (array) $row;
        unset($data['tenant_id'], $data['idempotency_key'], $data['request_hash']);
        $data['recorded_at'] = Carbon::parse($row->recorded_at)->toISOString();

        return $data;
    }

    private function page(Builder $query, array $data): LengthAwarePaginator
    {
        return $query->paginate((int) ($data['per_page'] ?? 15), page: (int) ($data['page'] ?? 1))
            ->through(fn (stdClass $row) => $this->present($row));
    }

    private function pageRules(): array
    {
        return ['page' => ['sometimes', 'integer', 'between:1,1000000'], 'per_page' => ['sometimes', 'integer', 'between:1,100']];
    }

    private function authorize(User $actor, string $action): void
    {
        $this->tenant->requireId();
        abort_unless($actor->isMemberOfCurrentTenant() && $actor->hasPermission('equipment.'.$action), 403);
    }

    private function asset(int $id): Builder
    {
        return $this->query(self::ASSETS)->where('id', $id);
    }

    private function findAsset(Builder $query): stdClass
    {
        $asset = $query->first();
        abort_if($asset === null, 404, 'Equipment not found.');

        return $asset;
    }

    private function query(string $table): Builder
    {
        return DB::table($table)->where('tenant_id', $this->tenant->requireId());
    }

    private function validate(array $input, array $rules): array
    {
        $unknown = array_diff(array_keys($input), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages([reset($unknown) => ['Unsupported equipment field.']]);
        }

        return Validator::make($input, $rules)->validate();
    }
}
