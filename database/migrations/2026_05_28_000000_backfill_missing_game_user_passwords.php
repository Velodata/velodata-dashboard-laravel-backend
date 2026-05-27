<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('game_users')
            ->where(function ($query) {
                $query->whereNull('password')
                    ->orWhere('password', '');
            })
            ->update([
                'password' => Hash::make('equinim01'),
                'must_change_password' => true,
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This is a production data repair. Do not remove repaired passwords on rollback.
    }
};
