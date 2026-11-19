<?php

namespace App\Models\Concerns;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

trait HasRolesAndPermissions
{
    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id');
    }

    public function isSuperAdmin(): bool
    {
        if ($this->relationLoaded('roles')) {
            return $this->roles->contains(fn (Role $role): bool => $role->slug === Role::SUPER_ADMIN_SLUG);
        }

        return $this->roles()->where('slug', Role::SUPER_ADMIN_SLUG)->exists();
    }

    /**
     * Compute effective permission names across all assigned roles.
     * Super Admin automatically holds every permission in the database.
     *
     * @return Collection<int, string>
     */
    public function effectivePermissions(): Collection
    {
        if ($this->isSuperAdmin()) {
            return Permission::query()->orderBy('name')->pluck('name');
        }

        $roles = $this->relationLoaded('roles')
            ? $this->roles->loadMissing('permissions')
            : $this->roles()->with('permissions')->get();

        return $roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique()
            ->sort()
            ->values();
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->effectivePermissions()->contains($permission);
    }

    /**
     * @param  list<string>  $permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $effective = $this->effectivePermissions();

        foreach ($permissions as $permission) {
            if ($effective->contains($permission)) {
                return true;
            }
        }

        return false;
    }
}
