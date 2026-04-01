<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    /**
     * @var array<string, list<string>>
     */
    private array $rolePermissions = [
        'system_admin' => [
            'auth.me.read', 'auth.password.change', 'system.access',
            'master.read', 'master.write', 'master.revert',
            'rentals.read', 'rentals.write', 'rentals.checkout', 'rentals.transfer.request', 'rentals.transfer.approve',
            'inventory.read', 'inventory.write', 'inventory.stocktake.write', 'inventory.stocktake.approve',
            'content.read', 'content.write', 'content.approve',
            'reviews.read', 'reviews.write', 'reviews.respond', 'reviews.moderate',
            'analytics.read',
            'imports.read', 'imports.write', 'imports.conflict.resolve',
            'exports.read',
            'dedup.scan', 'dedup.merge',
            'audit.read', 'audit.archive', 'audit.reindex',
        ],
        'clinic_manager' => [
            'auth.me.read', 'auth.password.change',
            'master.read', 'master.revert',
            'rentals.read', 'rentals.write', 'rentals.checkout', 'rentals.transfer.request', 'rentals.transfer.approve',
            'inventory.read', 'inventory.stocktake.approve',
            'content.read', 'content.approve',
            'reviews.read', 'reviews.write', 'reviews.respond', 'reviews.moderate',
            'analytics.read',
            'imports.read', 'imports.conflict.resolve',
            'exports.read',
            'dedup.scan', 'dedup.merge',
            'audit.read',
        ],
        'inventory_clerk' => [
            'auth.me.read', 'auth.password.change',
            'inventory.read', 'inventory.write', 'inventory.stocktake.write',
            'master.read',
        ],
        'technician_doctor' => [
            'auth.me.read', 'auth.password.change',
            'rentals.read', 'rentals.checkout',
            'inventory.read',
            'reviews.respond',
        ],
        'content_editor' => [
            'auth.me.read', 'auth.password.change',
            'content.read', 'content.write',
            'master.read',
        ],
        'content_approver' => [
            'auth.me.read', 'auth.password.change',
            'content.read', 'content.approve',
            'analytics.read',
        ],
    ];

    public function run(): void
    {
        $allPermissions = collect($this->rolePermissions)
            ->flatten()
            ->unique()
            ->values();

        $permissionMap = $allPermissions->mapWithKeys(function (string $permissionCode): array {
            $permission = Permission::query()->updateOrCreate(
                ['code' => $permissionCode],
                [
                    'name' => str_replace('.', ' ', ucfirst($permissionCode)),
                ],
            );

            return [$permissionCode => $permission->id];
        });

        foreach (array_keys($this->rolePermissions) as $roleCode) {
            $role = Role::query()->updateOrCreate(
                ['code' => $roleCode],
                ['name' => str_replace('_', ' ', ucfirst($roleCode))],
            );

            $permissionIds = collect($this->rolePermissions[$roleCode])
                ->map(fn (string $permissionCode): int => (int) $permissionMap[$permissionCode])
                ->all();

            $role->permissions()->sync($permissionIds);
        }
    }
}
