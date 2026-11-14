<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InsurancePlanVersion extends Model
{
    use BelongsToTenant;

    protected $guarded = ['*'];

    protected $casts = [
        'version' => 'integer', 'lock_version' => 'integer',
        'effective_from' => 'date', 'effective_to' => 'date',
        'reviewed_at' => 'datetime', 'published_at' => 'datetime',
    ];

    public function plan()
    {
        return $this->belongsTo(InsurancePlan::class, 'insurance_plan_id');
    }
}
