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
    }

    public function down(): void
    {
        DB::table('roles')
            ->where('name', 'Trainer')
            ->where('guard_name', 'api')
            ->delete();
    }
};
