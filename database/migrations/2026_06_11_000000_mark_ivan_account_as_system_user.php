<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where(function ($query) {
                $query
                    ->where('id', 4)
                    ->orWhere('email', 'ivanvetsich@gmail.com');
            })
            ->update([
                'is_system_user' => true,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where(function ($query) {
                $query
                    ->where('id', 4)
                    ->orWhere('email', 'ivanvetsich@gmail.com');
            })
            ->where('email', 'ivanvetsich@gmail.com')
            ->update([
                'is_system_user' => false,
                'updated_at' => now(),
            ]);
    }
};
