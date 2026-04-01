<?php

namespace App\Models\Concerns;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait HasRbac
{
    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_user')->withTimestamps();
    }

    public function hasRole(string $roleCode): bool
    {
        return $this->roles->contains(fn (Role $role): bool => $role->code === $roleCode);
    }

    public function hasPermission(string $permissionCode): bool
    {
        if ($this->permissions->contains(fn (Permission $permission): bool => $permission->code === $permissionCode)) {
            return true;
        }

        return $this->roles
            ->flatMap(fn (Role $role) => $role->permissions)
            ->contains(fn (Permission $permission): bool => $permission->code === $permissionCode);
    }

    /**
     * @return list<string>
     */
    public function roleCodes(): array
    {
        return $this->roles->pluck('code')->values()->all();
    }

    /**
     * @return list<string>
     */
    public function permissionCodes(): array
    {
        $directPermissions = $this->permissions->pluck('code');
        $rolePermissions = $this->roles
            ->flatMap(fn (Role $role) => $role->permissions)
            ->pluck('code');

        return $directPermissions
            ->merge($rolePermissions)
            ->unique()
            ->values()
            ->all();
    }
}
