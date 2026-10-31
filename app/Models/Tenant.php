<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasFactory;

    public const DEFAULT_SLUG = 'default-clinic';

    protected $fillable = ['name', 'slug', 'active', 'contact_email', 'contact_phone', 'address', 'timezone', 'currency', 'branding'];

    protected $hidden = ['pivot', 'provisioning_key', 'provisioning_fingerprint', 'initial_admin_id'];

    protected $casts = ['active' => 'boolean', 'branding' => 'array'];

    protected $appends = ['status'];

    public function getStatusAttribute(): string
    {
        return $this->active ? 'active' : 'suspended';
    }

    public function users()
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function roles()
    {
        return $this->hasMany(Role::class)->withoutGlobalScope('tenant')->where('tenant_id', $this->id);
    }
}
