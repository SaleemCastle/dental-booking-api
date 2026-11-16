<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PatientInsurerAssociation extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['patient_id' => 'integer', 'insurer_id' => 'integer', 'coverage_priority' => 'integer', 'active' => 'boolean', 'lock_version' => 'integer'];

    public function insurer()
    {
        return $this->belongsTo(Insurer::class);
    }
}
