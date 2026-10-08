<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;

    protected $table = 'messages';

    public $timestamps = false;

    protected $fillable = [
        'sender_id',
        'receiver_id',
        'message_text',
        'timestamp',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
    ];

    public function sender()
    {
        return $this->belongsTo(Patient::class, 'sender_id');
    }

    public function receiver()
    {
        return $this->belongsTo(Patient::class, 'receiver_id');
    }
}
