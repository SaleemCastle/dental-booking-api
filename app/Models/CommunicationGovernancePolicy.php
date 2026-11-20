<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CommunicationGovernancePolicy extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['escalation_enabled' => 'boolean', 'retention_days' => 'integer', 'archive_after_days' => 'integer', 'version' => 'integer', 'recorded_at' => 'immutable_datetime'];
}
