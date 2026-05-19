<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->updateOrInsert(
            [
                'name' => 'Trainer',
                'guard_name' => 'api',
            ],
            [
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $trainerRoleId = DB::table('roles')
            ->whereRaw('LOWER(name) = ?', ['trainer'])
            ->value('id');

        $protectorRoleId = DB::table('roles')
            ->whereRaw('LOWER(name) = ?', ['protector'])
            ->value('id');

        if (! $trainerRoleId || ! $protectorRoleId) {
            return;
        }

        $protectorPermissionIds = DB::table('role_has_permissions')
            ->where('role_id', $protectorRoleId)
            ->pluck('permission_id');

        foreach ($protectorPermissionIds as $permissionId) {
            $exists = DB::table('role_has_permissions')
                ->where('role_id', $trainerRoleId)
                ->where('permission_id', $permissionId)
                ->exists();

            if (! $exists) {
                DB::table('role_has_permissions')->insert([
                    'role_id' => $trainerRoleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $trainerRoleId = DB::table('roles')
            ->whereRaw('LOWER(name) = ?', ['trainer'])
            ->value('id');

        if ($trainerRoleId) {
            DB::table('role_has_permissions')
                ->where('role_id', $trainerRoleId)
                ->delete();
        }
    }
};
