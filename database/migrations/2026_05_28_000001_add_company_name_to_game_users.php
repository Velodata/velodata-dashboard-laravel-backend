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
            if (! Schema::hasColumn('game_users', 'company_name')) {
                $table->string('company_name')->nullable()->after('email');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('game_users', function (Blueprint $table) {
            if (Schema::hasColumn('game_users', 'company_name')) {
                $table->dropColumn('company_name');
            }
        });
    }
};
