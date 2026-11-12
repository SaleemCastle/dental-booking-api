<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ConversationThread extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    public function participants()
    {
        return $this->hasMany(ConversationParticipant::class, 'thread_id');
    }

    public function messages()
    {
        return $this->hasMany(ConversationMessage::class, 'thread_id');
    }
}
