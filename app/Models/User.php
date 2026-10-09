<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'pivot',
    ];

    protected $appends = [
        'permissions',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function roles()
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function getPermissionsAttribute(): array
    {
        return $this->permissionSlugs();
    }

    public function permissionSlugs(): array
    {
        $this->loadMissing('roles.permissions');

        return $this->roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('slug'))
            ->unique()
            ->values()
            ->all();
    }

    public function hasRole(string $role): bool
    {
        $this->loadMissing('roles');

        return $this->roles->contains(
            fn (Role $assignedRole) => $assignedRole->slug === $role || $assignedRole->name === $role
        );
    }

    public function hasAnyRole(array $roles): bool
    {
        return collect($roles)->contains(fn (string $role) => $this->hasRole($role));
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissionSlugs(), true);
    }

    public function canAccessResource(string $resource, string $action): bool
    {
        $permissionPatterns = [
            "{$resource}.{$action}",
            "{$resource}.*",
            "*.{$action}",
            '*',
        ];

        return collect($this->permissionSlugs())
            ->contains(fn (string $permission) => in_array($permission, $permissionPatterns, true));
    }

    public function canPerform(string $ability, string|object|null $resource = null): bool
    {
        if ($this->hasPermission($ability)) {
            return true;
        }

        if ($resource === null) {
            return false;
        }

        return $this->canAccessResource($this->normalizeResourceName($resource), $ability);
    }

    private function normalizeResourceName(string|object $resource): string
    {
        if (is_object($resource)) {
            $resource = class_basename($resource);
        } elseif (class_exists($resource)) {
            $resource = class_basename($resource);
        }

        return Str::plural(Str::snake(str_replace(['-', ' '], '_', $resource)));
    }
}
