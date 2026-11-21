<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InsuranceClaim extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['draft', 'ready', 'submitted', 'acknowledged', 'under_review', 'additional_information_requested', 'rejected', 'approved', 'partially_approved', 'denied', 'cancelled'];

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['lock_version' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];

    public function lines()
    {
        return $this->hasMany(InsuranceClaimLine::class, 'claim_id')->orderBy('id');
    }

    public function submissions()
    {
        return $this->hasMany(InsuranceClaimSubmission::class, 'claim_id')->orderByDesc('sequence');
    }

    public function events()
    {
        return $this->hasMany(InsuranceClaimEvent::class, 'claim_id')->orderBy('version');
    }

    public function responses()
    {
        return $this->hasMany(InsuranceClaimResponse::class, 'claim_id')->orderByDesc('id');
    }

    public function appeals()
    {
        return $this->hasMany(InsuranceClaimAppeal::class, 'claim_id')->orderByDesc('id');
    }
}
