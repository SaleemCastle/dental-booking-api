<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InsuranceClaimAppeal extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['draft', 'submitted', 'acknowledged', 'under_review', 'approved', 'partially_approved', 'denied', 'withdrawn'];

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['created_at' => 'immutable_datetime'];

    public function events()
    {
        return $this->hasMany(InsuranceClaimAppealEvent::class, 'appeal_id')->orderBy('id');
    }

    public function submissions()
    {
        return $this->hasMany(InsuranceClaimAppealSubmission::class, 'appeal_id')->orderByDesc('sequence');
    }

    public function responses()
    {
        return $this->hasMany(InsuranceClaimAppealResponse::class, 'appeal_id')->orderBy('id');
    }
}
