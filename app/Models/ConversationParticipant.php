<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ConversationParticipant extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];
}
