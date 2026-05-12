<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('game_users')
            ->where('game_role', 'player')
            ->update([
                'game_role' => 'Creator',
                'updated_at' => now(),
            ]);

        DB::statement("ALTER TABLE game_users ALTER game_role SET DEFAULT 'Creator'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE game_users ALTER game_role SET DEFAULT 'player'");
    }
};
