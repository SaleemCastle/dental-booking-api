<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PatientContact extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id', 'verified_at', 'verified_by', 'verification_method', 'revoked_at'];

    protected $casts = ['address' => 'encrypted', 'authorized_events' => 'array', 'verified_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];

    protected $hidden = ['address'];

    protected static function booted(): void
    {
        static::updating(function ($contact) {
            if ($contact->isDirty(['patient_id', 'channel', 'address', 'role', 'authorized_events'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['contact' => ['Revoke and replace contacts when their address or authorization changes.']]);
            }
        });
    }
}
