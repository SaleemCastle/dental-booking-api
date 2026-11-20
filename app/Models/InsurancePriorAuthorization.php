<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InsurancePriorAuthorization extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['draft', 'requested', 'pending', 'approved', 'partially_approved', 'denied', 'cancelled', 'expired'];

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['command_key', 'request_hash'];

    protected $casts = ['request_date' => 'immutable_date', 'requested_amount' => 'decimal:2', 'procedure_details' => 'array', 'lock_version' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];

    public function events()
    {
        return $this->hasMany(InsurancePriorAuthorizationEvent::class, 'authorization_id')->orderBy('version');
    }

    public function decisions()
    {
        return $this->hasMany(InsurancePriorAuthorizationDecision::class, 'authorization_id')->orderBy('id');
    }
}
