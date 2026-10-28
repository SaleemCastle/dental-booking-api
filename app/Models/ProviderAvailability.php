<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ProviderAvailability extends Model
{
    use BelongsToTenant;

    protected $fillable = ['dentist_id', 'weekly_hours', 'breaks', 'exceptions'];

    protected $casts = [
        'dentist_id' => 'integer',
        'weekly_hours' => 'array',
        'breaks' => 'array',
        'exceptions' => 'array',
    ];

    public function dentist()
    {
        return $this->belongsTo(Dentist::class);
    }
}
