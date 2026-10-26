<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TreatmentPlan extends Model
{
    use BelongsToTenant, HasFactory;

    public const STATUSES = ['draft', 'active', 'completed', 'cancelled'];

    protected $fillable = ['title', 'notes', 'status'];

    protected $attributes = ['status' => 'draft'];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function items()
    {
        return $this->hasMany(TreatmentPlanItem::class)->orderBy('sequence')->orderBy('id');
    }
}
