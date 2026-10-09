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
        'archived_at',
        'archived_by_user_id',
    ];

    protected $casts = [
        'allergies' => 'array',
        'medications' => 'array',
        'medical_alerts' => 'array',
        'archived_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

    public function archive(?User $user = null): bool
    {
        return $this->forceFill([
            'archived_at' => $this->archived_at ?? now(),
            'archived_by_user_id' => $this->archived_by_user_id ?? $user?->id,
        ])->save();
    }

    public function archivedBy()
    {
        return $this->belongsTo(User::class, 'archived_by_user_id');
    }

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
