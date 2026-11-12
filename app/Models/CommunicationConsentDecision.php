<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CommunicationConsentDecision extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['allowed' => 'boolean', 'snapshot' => 'array', 'decided_at' => 'immutable_datetime'];
}
