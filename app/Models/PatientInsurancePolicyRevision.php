<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PatientInsurancePolicyRevision extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['policy_number', 'membership_id', 'subscriber_name', 'reason'];

    protected $casts = [
        'association_id' => 'integer', 'policy_id' => 'integer', 'association_revision_id' => 'integer', 'version' => 'integer', 'plan_id' => 'integer',
        'policy_number' => 'encrypted', 'membership_id' => 'encrypted', 'subscriber_name' => 'encrypted', 'reason' => 'encrypted',
    ];
}
