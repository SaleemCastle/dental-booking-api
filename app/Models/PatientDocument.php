<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PatientDocument extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $guarded = ['*'];

    protected $hidden = ['disk', 'storage_key'];

    protected $casts = [
        'patient_id' => 'integer',
        'appointment_id' => 'integer',
        'clinical_note_id' => 'integer',
        'size_bytes' => 'integer',
        'uploaded_by_user_id' => 'integer',
        'archived_by_user_id' => 'integer',
        'deleted_by_user_id' => 'integer',
        'archived_at' => 'datetime',
        'purged_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    public function clinicalNote()
    {
        return $this->belongsTo(ClinicalNote::class)->withTrashed();
    }
}
