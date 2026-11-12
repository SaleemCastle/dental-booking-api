<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ConversationMessage extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['payload_hash', 'idempotency_key'];

    protected $casts = ['recipient_ids' => 'array', 'audit_metadata' => 'array', 'created_at' => 'immutable_datetime'];
}
