<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InsuranceClaim extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['draft', 'ready', 'submitted', 'cancelled'];

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
}
