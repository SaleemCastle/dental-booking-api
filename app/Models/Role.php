<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    public const ADMIN = 'admin';

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
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class)->withTimestamps();
    }
}
