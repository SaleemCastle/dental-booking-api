<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use BelongsToTenant, HasFactory;

    public const ADMIN = 'admin';

    public const CLINIC_ADMIN = self::ADMIN;

    public const DENTIST = 'dentist';

    public const HYGIENIST = 'hygienist';

    public const RECEPTIONIST = 'receptionist';

    public const BILLING = 'billing';

    public const AUDITOR = 'auditor';

    public const SYSTEM_ROLE_SLUGS = [
        self::ADMIN,
        self::DENTIST,
        self::HYGIENIST,
        self::RECEPTIONIST,
        self::BILLING,
        self::AUDITOR,
    ];

    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_system',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    protected $hidden = [
        'pivot',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class)->withTimestamps()->withPivotValue('tenant_id', $this->tenant_id);
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class)->withTimestamps();
    }
}
