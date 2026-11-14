<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InsurancePlan extends Model
{
    use BelongsToTenant;

    protected $guarded = ['*'];

    protected $casts = ['active' => 'boolean', 'lock_version' => 'integer'];

    public function insurer()
    {
        return $this->belongsTo(Insurer::class);
    }

    public function versions()
    {
        return $this->hasMany(InsurancePlanVersion::class);
    }
}
