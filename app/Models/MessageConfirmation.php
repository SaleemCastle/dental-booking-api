<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class MessageConfirmation extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['token_hash', 'payload_hash'];

    protected $casts = ['expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime'];
}
