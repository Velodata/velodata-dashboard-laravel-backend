<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionNames = [
            'view user-audit-history',
            'view user-login-history',
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

        $roleIdsWithUserView = DB::table('role_has_permissions')
            ->join('permissions', 'role_has_permissions.permission_id', '=', 'permissions.id')
            ->where('permissions.name', 'view users')
            ->pluck('role_has_permissions.role_id')
            ->unique();

        $historyPermissionIds = DB::table('permissions')
            ->whereIn('name', $permissionNames)
            ->pluck('id');

        foreach ($roleIdsWithUserView as $roleId) {
            foreach ($historyPermissionIds as $permissionId) {
                $exists = DB::table('role_has_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->exists();

                if (! $exists) {
                    DB::table('role_has_permissions')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', ['view user-audit-history', 'view user-login-history'])
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
