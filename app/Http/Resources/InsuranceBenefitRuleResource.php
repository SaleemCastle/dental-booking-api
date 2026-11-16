<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InsuranceBenefitRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource->only([
                'id', 'plan_version_id', 'association_id', 'association_revision_id', 'policy_revision_id',
                'supersedes_id', 'revision', 'source', 'scope', 'category', 'procedure_code', 'code_system',
                'coverage', 'benefit_type', 'percentage', 'amount', 'currency', 'annual_limit', 'visit_limit',
                'procedure_limit', 'waiting_period_days', 'prior_authorization_required', 'service_from', 'service_to', 'actor_id',
            ]),
            'confirmation' => 'unconfirmed',
            'observed_at' => $this->observed_at->toISOString(), 'recorded_at' => $this->recorded_at->toISOString(),
            'has_reference' => $this->reference !== null,
        ];
    }
}
