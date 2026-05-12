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
        Schema::table('game_users', function (Blueprint $table) {
            if (! Schema::hasColumn('game_users', 'gender')) {
                $table->string('gender', 50)->nullable()->after('display_name');
            }

            if (! Schema::hasColumn('game_users', 'languages')) {
                $table->json('languages')->nullable()->after('location');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('game_users', function (Blueprint $table) {
            foreach (['languages', 'gender'] as $column) {
                if (Schema::hasColumn('game_users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
