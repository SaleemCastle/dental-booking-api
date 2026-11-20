<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ConversationParticipant extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];

    public function accessState()
    {
        return $this->hasOne(ConversationAccessState::class, 'participant_id');
    }

    public function scopeActive($query)
    {
        return $query->whereDoesntHave('accessState', fn ($state) => $state->where('active', false));
    }
}
