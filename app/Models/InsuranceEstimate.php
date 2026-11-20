<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InsuranceEstimate extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['service_date' => 'date', 'calculation' => 'array', 'recorded_at' => 'datetime'];

    public function treatmentPlanItem()
    {
        return $this->belongsTo(TreatmentPlanItem::class);
    }
}
