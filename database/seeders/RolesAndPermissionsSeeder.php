<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Seed the application's roles and permissions.
     */
    public function run(): void
    {
        $permissionsByName = collect(config('permissions.permissions', []))
            ->flatMap(fn (array $permissions, string $group) => collect($permissions)->mapWithKeys(
                fn (string $permission) => [$permission => Permission::query()->updateOrCreate(
                    ['name' => $permission],
                    [
                        'display_name' => Str::headline($permission),
                        'group' => $group,
                        'is_system' => true,
                    ],
                )],
            ));

        foreach (config('permissions.roles', []) as $name => $definition) {
            $role = Role::query()->updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $definition['display_name'],
                    'all_permissions' => $definition['all_permissions'] ?? false,
                    'priority' => $definition['priority'] ?? 0,
                    'is_system' => true,
                ],
            );

            if ($role->all_permissions) {
                $role->permissions()->sync([]);

                continue;
            }

            $role->permissions()->sync(
                collect($definition['permissions'] ?? [])
                    ->map(fn (string $permission) => $permissionsByName[$permission]?->id)
                    ->filter()
                    ->values()
                    ->all(),
            );
        }
    }
}
