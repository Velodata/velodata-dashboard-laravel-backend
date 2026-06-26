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
        Schema::table('browser_identities', function (Blueprint $table) {
            if (! Schema::hasColumn('browser_identities', 'report_count')) {
                $table->unsignedInteger('report_count')->default(0)->after('last_notification_identity_count');
            }
        });

        Schema::create('browser_identity_ip_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('browser_identity_id')->constrained('browser_identities')->cascadeOnDelete();
            $table->string('browser_uuid', 80);
            $table->string('ip_address', 45);
            $table->string('ip_address_v4', 45)->nullable();
            $table->string('ip_address_v6', 45)->nullable();
            $table->timestamps();

            $table->unique(['browser_identity_id', 'ip_address'], 'browser_identity_ip_unique');
            $table->index('browser_uuid');
            $table->index('ip_address');
            $table->index('ip_address_v4');
            $table->index('ip_address_v6');
        });

        Schema::create('browser_identity_notification_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('browser_identity_id')->constrained('browser_identities')->cascadeOnDelete();
            $table->string('browser_uuid', 80);
            $table->string('email');
            $table->unsignedInteger('notification_count')->default(0);
            $table->timestamps();

            $table->unique(['browser_identity_id', 'email'], 'browser_identity_notification_email_unique');
            $table->index('browser_uuid');
            $table->index('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('browser_identity_notification_emails');
        Schema::dropIfExists('browser_identity_ip_addresses');

        Schema::table('browser_identities', function (Blueprint $table) {
            if (Schema::hasColumn('browser_identities', 'report_count')) {
                $table->dropColumn('report_count');
            }
        });
    }
};
