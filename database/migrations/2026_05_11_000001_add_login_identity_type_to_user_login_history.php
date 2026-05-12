<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_login_history', function (Blueprint $table) {
            if (! Schema::hasColumn('user_login_history', 'login_identity_type')) {
                $table->string('login_identity_type', 30)->default('staff')->after('name');
            }
        });

        DB::table('user_login_history')
            ->whereNull('login_identity_type')
            ->update(['login_identity_type' => 'staff']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_login_history', function (Blueprint $table) {
            if (Schema::hasColumn('user_login_history', 'login_identity_type')) {
                $table->dropColumn('login_identity_type');
            }
        });
    }
};
