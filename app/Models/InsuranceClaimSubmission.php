<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InsuranceClaimSubmission extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['sequence' => 'integer', 'claim_version' => 'integer', 'channel_metadata' => 'array', 'snapshot' => 'array', 'submitted_at' => 'immutable_datetime'];
}
