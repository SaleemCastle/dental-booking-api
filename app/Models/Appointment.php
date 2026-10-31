<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    use BelongsToTenant, HasFactory;

    public const ACTIVE_STATUSES = ['scheduled', 'checked_in', 'in_progress'];

    public const STATUSES = ['scheduled', 'checked_in', 'in_progress', 'completed', 'cancelled', 'no_show'];

    protected $table = 'appointment';

    protected $fillable = [
        'patient_id',
        'dentist_id',
        'treatment_id',
        'room_id',
        'chair_id',
        'appointment_date_time',
        'appointment_type',
        'description',
        'status',
        'duration_minutes',
        'checked_in_at',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = ['communication_revision' => 'integer',
        'patient_id' => 'integer',
        'dentist_id' => 'integer',
        'treatment_id' => 'integer',
        'room_id' => 'integer',
        'chair_id' => 'integer',
        'duration_minutes' => 'integer',
        'appointment_date_time' => 'datetime',
        'checked_in_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function dentist()
    {
        return $this->belongsTo(Dentist::class);
    }

    public function treatment()
    {
        return $this->belongsTo(Treatment::class);
    }

    public function invoice()
    {
        return $this->hasOne(Invoice::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function chair()
    {
        return $this->belongsTo(Chair::class);
    }

    public function clinicalNotes()
    {
        return $this->hasMany(ClinicalNote::class);
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }
}
