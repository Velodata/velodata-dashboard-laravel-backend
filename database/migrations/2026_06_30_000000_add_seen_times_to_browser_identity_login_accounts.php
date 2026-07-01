<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('browser_identity_login_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('browser_identity_login_accounts', 'first_seen_at')) {
                $table->dateTime('first_seen_at')->nullable()->after('intake_code');
            }

            if (! Schema::hasColumn('browser_identity_login_accounts', 'last_seen_at')) {
                $table->dateTime('last_seen_at')->nullable()->after('first_seen_at');
            }

            if (! Schema::hasColumn('browser_identity_login_accounts', 'first_ip_address_v4')) {
                $table->string('first_ip_address_v4', 45)->nullable()->after('last_seen_at');
            }

            if (! Schema::hasColumn('browser_identity_login_accounts', 'last_ip_address_v4')) {
                $table->string('last_ip_address_v4', 45)->nullable()->after('first_ip_address_v4');
            }

            if (! Schema::hasColumn('browser_identity_login_accounts', 'report_count')) {
                $table->unsignedInteger('report_count')->default(0)->after('last_ip_address_v4');
            }
        });
    }

    public function down(): void
    {
        Schema::table('browser_identity_login_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('browser_identity_login_accounts', 'report_count')) {
                $table->dropColumn('report_count');
            }

            if (Schema::hasColumn('browser_identity_login_accounts', 'last_seen_at')) {
                $table->dropColumn('last_seen_at');
            }

            if (Schema::hasColumn('browser_identity_login_accounts', 'last_ip_address_v4')) {
                $table->dropColumn('last_ip_address_v4');
            }

            if (Schema::hasColumn('browser_identity_login_accounts', 'first_ip_address_v4')) {
                $table->dropColumn('first_ip_address_v4');
            }

            if (Schema::hasColumn('browser_identity_login_accounts', 'first_seen_at')) {
                $table->dropColumn('first_seen_at');
            }
        });
    }
};
