<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InsuranceBenefitRule extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['provenance', 'reference', 'reason'];

    protected $casts = [
        'percentage' => 'decimal:2', 'amount' => 'decimal:2', 'annual_limit' => 'decimal:2',
        'visit_limit' => 'decimal:2', 'procedure_limit' => 'decimal:2',
        'prior_authorization_required' => 'boolean', 'observed_at' => 'datetime', 'recorded_at' => 'datetime',
        'provenance' => 'encrypted', 'reference' => 'encrypted', 'reason' => 'encrypted',
    ];
}
