<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PatientInsuranceCheck extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['provenance', 'reference'];

    protected $casts = [
        'association_id' => 'integer', 'association_revision_id' => 'integer', 'policy_revision_id' => 'integer',
        'provenance' => 'encrypted', 'reference' => 'encrypted', 'checked_at' => 'datetime',
    ];
}
