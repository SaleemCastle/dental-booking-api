<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['name'];

    public function chairs()
    {
        return $this->hasMany(Chair::class);
    }
}
