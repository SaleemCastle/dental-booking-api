<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasFactory;

    public const DEFAULT_SLUG = 'default-clinic';

    protected $fillable = ['name', 'slug', 'active'];

    protected $hidden = ['pivot'];

    protected $casts = ['active' => 'boolean'];

    public function users()
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function roles()
    {
        return $this->hasMany(Role::class)->withoutGlobalScope('tenant')->where('tenant_id', $this->id);
    }
}
