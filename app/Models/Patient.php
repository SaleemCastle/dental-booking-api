<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Patient extends Model
{
    use HasFactory;

    protected $table = 'patient';

    protected $fillable = [
        'firstName',
        'lastName',
        'appointments',
        'sex',
        'streetAddress',
        'town',
        'city',
        'notes',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relationship',
        'allergies',
        'medications',
        'medical_alerts',
    ];

    protected $casts = [
        'allergies' => 'array',
        'medications' => 'array',
        'medical_alerts' => 'array',
    ];

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    public function sentMessages()
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function receivedMessages()
    {
        return $this->hasMany(Message::class, 'receiver_id');
    }

    public function referrals()
    {
        return $this->hasMany(Referral::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function clinicalNotes()
    {
        return $this->hasMany(ClinicalNote::class);
    }
}
