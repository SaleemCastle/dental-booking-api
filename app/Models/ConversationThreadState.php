<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ConversationThreadState extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['legal_hold' => 'boolean', 'archived_at' => 'immutable_datetime', 'last_activity_at' => 'immutable_datetime'];
}
