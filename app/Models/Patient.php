<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Patient extends Model
{
    use BelongsToTenant, HasFactory;

    public function dentalChartRevisions()
    {
        return $this->hasMany(DentalChartRevision::class);
    }

    public function treatmentPlans()
    {
        return $this->hasMany(TreatmentPlan::class);
    }

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

    public function scopeSearch($query, string $search)
    {
        $search = trim($search);

        return $query->where(function ($query) use ($search) {
            if (ctype_digit($search)) {
                $query->orWhere('id', (int) $search);
            }

            $like = "%{$search}%";

            $query
                ->orWhere('firstName', 'like', $like)
                ->orWhere('lastName', 'like', $like)
                ->orWhere('streetAddress', 'like', $like)
                ->orWhere('town', 'like', $like)
                ->orWhere('city', 'like', $like)
                ->orWhere('notes', 'like', $like)
                ->orWhere('emergency_contact_name', 'like', $like)
                ->orWhere('emergency_contact_phone', 'like', $like)
                ->orWhere('emergency_contact_relationship', 'like', $like);

            collect(preg_split('/\s+/', $search, flags: PREG_SPLIT_NO_EMPTY))
                ->filter(fn (string $term) => strlen($term) >= 2)
                ->each(function (string $term) use ($query): void {
                    $termLike = "%{$term}%";

                    $query->orWhere(function ($query) use ($termLike): void {
                        $query
                            ->where('firstName', 'like', $termLike)
                            ->orWhere('lastName', 'like', $termLike);
                    });
                });
        });
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

    public function documents()
    {
        return $this->hasMany(PatientDocument::class);
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }
}
