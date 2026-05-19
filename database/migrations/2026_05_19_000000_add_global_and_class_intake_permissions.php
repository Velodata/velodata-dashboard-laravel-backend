<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionNames = [
            'view global-management',
            'view class-intake-management',
        ];

        foreach ($permissionNames as $permissionName) {
            DB::table('permissions')->updateOrInsert(
                [
                    'name' => $permissionName,
                    'guard_name' => 'api',
                ],
                [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $globalPermissionId = DB::table('permissions')
            ->where('name', 'view global-management')
            ->value('id');

        $classIntakePermissionId = DB::table('permissions')
            ->where('name', 'view class-intake-management')
            ->value('id');

        $globalRoleIds = DB::table('roles')
            ->whereIn('name', ['Admin', 'Trainer'])
            ->pluck('id');

        $adminRoleIds = DB::table('roles')
            ->where('name', 'Admin')
            ->pluck('id');

        foreach ($globalRoleIds as $roleId) {
            DB::table('role_has_permissions')->updateOrInsert([
                'role_id' => $roleId,
                'permission_id' => $globalPermissionId,
            ]);
        }

        foreach ($adminRoleIds as $roleId) {
            DB::table('role_has_permissions')->updateOrInsert([
                'role_id' => $roleId,
                'permission_id' => $classIntakePermissionId,
            ]);
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', ['view global-management', 'view class-intake-management'])
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('role_has_permissions')
                ->whereIn('permission_id', $permissionIds)
                ->delete();

            DB::table('permissions')
                ->whereIn('id', $permissionIds)
                ->delete();
        }
    }
};
