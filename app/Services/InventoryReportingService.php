<?php

namespace App\Services;

use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class InventoryReportingService
{
    public function __construct(private readonly TenantContext $tenant, private readonly AuditLogService $audit) {}

    public function lowStock(array $input, User $actor): array
    {
        $data = $this->parameters($input, [
            'threshold' => ['sometimes', 'required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?\z/'],
        ], $actor);
        [$whole, $fraction] = array_pad(explode('.', $data['threshold'] ?? '0', 2), 2, '');
        $threshold = $whole.'.'.str_pad($fraction, 6, '0');

        return $this->audited('low_stock_viewed', $data, $actor, function (string $asOf, ?int $location) use ($threshold) {
            $stock = $this->ledger($location)
                ->where(function (Builder $query) use ($asOf) {
                    $query->whereNull('lots.expires_on')->orWhere('lots.expires_on', '>=', $asOf);
                })
                ->select('movements.item_id')
                ->selectRaw('SUM(movements.quantity_delta) AS quantity')
                ->groupBy('movements.item_id');
            $items = DB::table('inventory_items as items')
                ->where('items.tenant_id', $this->tenant->requireId())
                ->leftJoinSub($stock, 'stock', 'stock.item_id', '=', 'items.id')
                ->whereRaw('COALESCE(stock.quantity, 0) <= CAST(? AS numeric)', [$threshold])
                ->select('items.id as item_id', 'items.sku', 'items.name', 'items.base_unit')
                ->selectRaw('ROUND(COALESCE(stock.quantity, 0), 6)::text AS usable_quantity')
                ->orderByRaw('COALESCE(stock.quantity, 0)')->orderBy('items.id');

            return [$items, ['threshold' => $threshold]];
        });
    }

    public function expiry(array $input, User $actor): array
    {
        $data = $this->parameters($input, [
            'days' => ['sometimes', 'integer', 'between:0,365'],
        ], $actor);
        $days = (int) ($data['days'] ?? 30);

        return $this->audited('expiry_viewed', $data, $actor, function (string $asOf, ?int $location) use ($days) {
            $through = Carbon::parse($asOf, 'UTC')->addDays($days)->toDateString();
            $lots = $this->ledger($location)
                ->join('inventory_items as items', function (JoinClause $join) {
                    $join->on('items.tenant_id', '=', 'movements.tenant_id')->on('items.id', '=', 'movements.item_id');
                })
                ->join('inventory_locations as locations', function (JoinClause $join) {
                    $join->on('locations.tenant_id', '=', 'movements.tenant_id')->on('locations.id', '=', 'movements.location_id');
                })
                ->where('lots.expires_on', '<=', $through)
                ->select('movements.item_id', 'items.sku', 'items.name', 'items.base_unit', 'movements.location_id',
                    'locations.code as location_code', 'movements.lot_id', 'lots.code as lot_code', 'lots.expires_on')
                ->selectRaw('ROUND(SUM(movements.quantity_delta), 6)::text AS physical_quantity')
                ->selectRaw("CASE WHEN lots.expires_on < CAST(? AS date) THEN 'expired' ELSE 'expiring' END AS status", [$asOf])
                ->groupBy('movements.item_id', 'items.sku', 'items.name', 'items.base_unit', 'movements.location_id',
                    'locations.code', 'movements.lot_id', 'lots.code', 'lots.expires_on')
                ->havingRaw('SUM(movements.quantity_delta) > 0')
                ->orderBy('lots.expires_on')->orderBy('movements.item_id')->orderBy('movements.location_id')->orderBy('movements.lot_id');

            return [$lots, ['days' => $days, 'through' => $through]];
        });
    }

    private function ledger(?int $location): Builder
    {
        $query = DB::table('inventory_movements as movements')
            ->where('movements.tenant_id', $this->tenant->requireId())
            ->leftJoin('inventory_lots as lots', function (JoinClause $join) {
                $join->on('lots.tenant_id', '=', 'movements.tenant_id')
                    ->on('lots.item_id', '=', 'movements.item_id')
                    ->on('lots.id', '=', 'movements.lot_id');
            });
        if ($location !== null) {
            $query->where('movements.location_id', $location);
        }

        return $query;
    }

    private function parameters(array $input, array $specific, User $actor): array
    {
        $this->tenant->requireId();
        abort_unless($actor->isMemberOfCurrentTenant() && $actor->hasPermission('inventory_reports.view'), 403);
        $rules = $specific + [
            'location_id' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'between:1,1000000'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
        $unknown = array_diff(array_keys($input), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages([reset($unknown) => ['Unsupported inventory report parameter.']]);
        }

        return Validator::make($input, $rules)->validate();
    }

    private function audited(string $event, array $data, User $actor, callable $build): array
    {
        return DB::transaction(function () use ($event, $data, $actor, $build) {
            $tenant = $this->tenant->requireId();
            $location = isset($data['location_id']) ? (int) $data['location_id'] : null;
            if ($location !== null) {
                abort_unless(DB::table('inventory_locations')->where('tenant_id', $tenant)->where('id', $location)->exists(), 404, 'Inventory location not found.');
            }
            $asOf = DB::selectOne("SELECT timezone('UTC', clock_timestamp())::date::text AS as_of")->as_of;
            [$query, $criteria] = $build($asOf, $location);
            $page = $query->simplePaginate((int) ($data['per_page'] ?? 15), ['*'], 'page', (int) ($data['page'] ?? 1));
            $this->audit->record('inventory_reports.'.$event, 'inventory_reports', $tenant, $actor->id);

            return ['page' => $page, 'criteria' => ['as_of' => $asOf, 'location_id' => $location, ...$criteria]];
        }, 3);
    }
}
