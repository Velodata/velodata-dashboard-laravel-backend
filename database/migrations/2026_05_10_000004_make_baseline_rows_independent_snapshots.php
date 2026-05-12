<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_table_baseline_rows', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('game_baseline_users', function (Blueprint $table) {
            $table->dropForeign(['game_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_table_baseline_rows', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('game_baseline_users', function (Blueprint $table) {
            $table->foreign('game_user_id')->references('id')->on('game_users')->cascadeOnDelete();
        });
    }
};
