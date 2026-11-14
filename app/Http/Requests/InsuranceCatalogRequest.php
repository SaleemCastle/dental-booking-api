<?php

namespace App\Http\Requests;

use App\Insurance\CatalogInput;
use App\Models\InsurancePlan;
use App\Models\InsurancePlanVersion;
use App\Models\Insurer;
use Illuminate\Foundation\Http\FormRequest;

class InsuranceCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        $operation = $this->route()->getActionMethod();
        $version = $this->route('version');
        $plan = $this->route('plan');
        $insurer = $this->route('insurer');

        return match ($operation) {
            'storeInsurer' => $this->user()->can('create', Insurer::class),
            'updateInsurer', 'archiveInsurer' => $this->user()->can('update', $insurer),
            'storePlan' => $this->user()->can('update', $insurer) && $this->user()->can('create', InsurancePlan::class),
            'updatePlan', 'archivePlan' => $this->user()->can('update', $plan),
            'storeVersion' => $this->user()->can('update', $plan) && $this->user()->can('create', InsurancePlanVersion::class),
            'reviewVersion' => $this->user()->can('review', $version),
            'publishVersion' => $this->user()->can('publish', $version),
            default => $this->user()->can('update', $version),
        };
    }

    public function rules(): array
    {
        return CatalogInput::rules($this->route()->getActionMethod(), $this->route('version') ?? $this->route('plan') ?? $this->route('insurer'));
    }
}
