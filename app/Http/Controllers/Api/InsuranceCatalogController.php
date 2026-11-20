<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InsuranceCatalogRequest;
use App\Http\Resources\InsurancePlanResource;
use App\Http\Resources\InsurancePlanVersionResource;
use App\Http\Resources\InsurerResource;
use App\Models\InsurancePlan;
use App\Models\InsurancePlanVersion;
use App\Models\Insurer;
use App\Services\InsuranceCatalogWorkflow;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class InsuranceCatalogController extends Controller
{
    public function __construct(private readonly InsuranceCatalogWorkflow $workflow) {}

    public function indexInsurers(Request $request)
    {
        return $this->page($this->workflow->insurers($request->query(), $request->user()), InsurerResource::class);
    }

    public function showInsurer(Request $request, Insurer $insurer)
    {
        return $this->record('insurer', new InsurerResource($this->workflow->insurer($insurer, $request->user())));
    }

    public function storeInsurer(InsuranceCatalogRequest $request)
    {
        return $this->record('insurer', new InsurerResource($this->workflow->createInsurer($request->all(), $request->user())), 201);
    }

    public function updateInsurer(InsuranceCatalogRequest $request, Insurer $insurer)
    {
        return $this->record('insurer', new InsurerResource($this->workflow->updateInsurer($insurer, $request->all(), $request->user())));
    }

    public function archiveInsurer(InsuranceCatalogRequest $request, Insurer $insurer)
    {
        return $this->record('insurer', new InsurerResource($this->workflow->updateInsurer($insurer, $request->all(), $request->user(), true)));
    }

    public function indexPlans(Request $request, Insurer $insurer)
    {
        return $this->page($this->workflow->plans($insurer, $request->query(), $request->user()), InsurancePlanResource::class);
    }

    public function showPlan(Request $request, InsurancePlan $plan)
    {
        return $this->record('plan', new InsurancePlanResource($this->workflow->plan($plan, $request->user())));
    }

    public function storePlan(InsuranceCatalogRequest $request, Insurer $insurer)
    {
        return $this->record('plan', new InsurancePlanResource($this->workflow->createPlan($insurer, $request->all(), $request->user())), 201);
    }

    public function updatePlan(InsuranceCatalogRequest $request, InsurancePlan $plan)
    {
        return $this->record('plan', new InsurancePlanResource($this->workflow->updatePlan($plan, $request->all(), $request->user())));
    }

    public function archivePlan(InsuranceCatalogRequest $request, InsurancePlan $plan)
    {
        return $this->record('plan', new InsurancePlanResource($this->workflow->updatePlan($plan, $request->all(), $request->user(), true)));
    }

    public function indexVersions(Request $request, InsurancePlan $plan)
    {
        return $this->page($this->workflow->versions($plan, $request->query(), $request->user()), InsurancePlanVersionResource::class);
    }

    public function showVersion(Request $request, InsurancePlanVersion $version)
    {
        return $this->record('plan_version', new InsurancePlanVersionResource($this->workflow->version($version, $request->user())));
    }

    public function storeVersion(InsuranceCatalogRequest $request, InsurancePlan $plan)
    {
        return $this->record('plan_version', new InsurancePlanVersionResource($this->workflow->createVersion($plan, $request->all(), $request->user())), 201);
    }

    public function updateVersion(InsuranceCatalogRequest $request, InsurancePlanVersion $version)
    {
        return $this->record('plan_version', new InsurancePlanVersionResource($this->workflow->updateVersion($version, $request->all(), $request->user())));
    }

    public function withdrawVersion(InsuranceCatalogRequest $request, InsurancePlanVersion $version)
    {
        return $this->transition($request, $version, 'withdraw');
    }

    public function reviewVersion(InsuranceCatalogRequest $request, InsurancePlanVersion $version)
    {
        return $this->transition($request, $version, 'review');
    }

    public function publishVersion(InsuranceCatalogRequest $request, InsurancePlanVersion $version)
    {
        return $this->transition($request, $version, 'publish');
    }

    private function transition(InsuranceCatalogRequest $request, InsurancePlanVersion $version, string $action)
    {
        return $this->record('plan_version', new InsurancePlanVersionResource($this->workflow->transition($version, $request->all(), $request->user(), $action)));
    }

    private function record(string $key, $resource, int $status = 200)
    {
        return ApiResponse::success([$key => $resource], 'Insurance catalog resource retrieved.', $status)->header('Cache-Control', 'private, no-store');
    }

    private function page($page, string $resource)
    {
        $page->setCollection($page->getCollection()->map(fn ($item) => new $resource($item)));

        return ApiResponse::success($page, 'Insurance catalog retrieved.')->header('Cache-Control', 'private, no-store');
    }
}
