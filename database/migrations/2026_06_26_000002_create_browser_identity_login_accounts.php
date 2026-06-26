<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('browser_identity_login_accounts')) {
            Schema::create('browser_identity_login_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('browser_identity_id')->constrained('browser_identities')->cascadeOnDelete();
                $table->string('browser_uuid', 80);
                $table->string('email');
                $table->string('identity_type', 30)->nullable();
                $table->string('name')->nullable();
                $table->string('role_name')->nullable();
                $table->string('intake_code', 80)->nullable();

                $table->unique(['browser_identity_id', 'email'], 'browser_identity_login_account_unique');
                $table->index('browser_uuid');
                $table->index('email');
                $table->index('identity_type');
                $table->index('intake_code');
            });
        }

        DB::statement("INSERT IGNORE INTO browser_identity_login_accounts (browser_identity_id, browser_uuid, email, identity_type, intake_code)
            SELECT id, browser_uuid, LOWER(TRIM(last_current_user_email)), last_current_user_identity_type, last_selected_game_intake_code
            FROM browser_identities
            WHERE last_current_user_email IS NOT NULL AND TRIM(last_current_user_email) <> ''");

        if (Schema::hasTable('browser_identity_events')) {
            DB::statement("INSERT IGNORE INTO browser_identity_login_accounts (browser_identity_id, browser_uuid, email, identity_type, intake_code)
                SELECT browser_identity_id, browser_uuid, LOWER(TRIM(current_user_email)), current_user_identity_type, selected_game_intake_code
                FROM browser_identity_events
                WHERE current_user_email IS NOT NULL AND TRIM(current_user_email) <> ''
                GROUP BY browser_identity_id, browser_uuid, LOWER(TRIM(current_user_email)), current_user_identity_type, selected_game_intake_code");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('browser_identity_login_accounts');
    }
};