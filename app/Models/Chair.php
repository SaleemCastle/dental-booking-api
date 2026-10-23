<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Chair extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['room_id', 'name'];

    protected $casts = ['room_id' => 'integer'];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}
