<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Insurer extends Model
{
    use BelongsToTenant;

    protected $guarded = ['*'];

    protected $casts = ['active' => 'boolean', 'lock_version' => 'integer'];

    public function plans()
    {
        return $this->hasMany(InsurancePlan::class);
    }
}
