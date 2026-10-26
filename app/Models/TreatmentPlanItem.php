<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TreatmentPlanItem extends Model
{
    use BelongsToTenant, HasFactory;

    public const STATUSES = ['proposed', 'in_progress', 'completed', 'cancelled'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $fillable = ['dentist_id', 'tooth_context', 'estimated_price', 'sequence', 'priority', 'status', 'notes'];

    protected $attributes = ['status' => 'proposed', 'priority' => 'normal', 'sequence' => 1];

    protected $casts = ['tooth_context' => 'array', 'catalog_price' => 'decimal:2', 'estimated_price' => 'decimal:2', 'sequence' => 'integer'];

    public function treatmentPlan()
    {
        return $this->belongsTo(TreatmentPlan::class);
    }

    public function treatment()
    {
        return $this->belongsTo(Treatment::class);
    }

    public function dentist()
    {
        return $this->belongsTo(Dentist::class);
    }
}
