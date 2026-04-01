<?php

namespace Database\Seeders;

use App\Models\Facility;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class AuthUsersSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    private array $roleCodes = [
        'system_admin',
        'clinic_manager',
        'inventory_clerk',
        'technician_doctor',
        'content_editor',
        'content_approver',
    ];

    public function run(): void
    {
        $facility = Facility::query()->first();

        foreach ($this->roleCodes as $roleCode) {
            $username = str_replace('_', '.', $roleCode);

            $user = User::query()->updateOrCreate(
                ['username' => $username],
                [
                    'name' => ucfirst(str_replace('_', ' ', $roleCode)),
                    'display_name' => ucfirst(str_replace('_', ' ', $roleCode)),
                    'email' => sprintf('%s@vetops.local', $username),
                    'password' => 'VetOpsSecure123',
                    'password_changed_at' => now()->utc(),
                    'is_active' => true,
                ],
            );

            $role = Role::query()->where('code', $roleCode)->first();
            if ($role !== null) {
                $user->roles()->syncWithoutDetaching([$role->id]);
            }

            if ($facility !== null) {
                $user->facilities()->syncWithoutDetaching([$facility->id]);
            }
        }
    }
}
