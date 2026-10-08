<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    use HasFactory;

    protected $table = 'appointment';

    protected $fillable = [
        'patient_id',
        'dentist_id',
        'treatment_id',
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

    protected $casts = [
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
}
