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
        Schema::create('browser_identities', function (Blueprint $table) {
            $table->id();
            $table->string('browser_uuid', 80)->unique();
            $table->dateTime('first_seen_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->string('first_ip_address', 45)->nullable();
            $table->string('last_ip_address', 45)->nullable();
            $table->string('first_user_agent_hash', 64)->nullable();
            $table->string('last_user_agent_hash', 64)->nullable();
            $table->string('last_current_user_email')->nullable();
            $table->string('last_current_user_identity_type', 30)->nullable();
            $table->string('last_selected_game_intake_code', 80)->nullable();
            $table->unsignedInteger('last_notification_identity_count')->default(0);
            $table->timestamps();

            $table->index('last_current_user_email');
            $table->index('last_selected_game_intake_code');
        });

        Schema::create('browser_identity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('browser_identity_id')->constrained('browser_identities')->cascadeOnDelete();
            $table->string('browser_uuid', 80);
            $table->string('event_type', 80);
            $table->string('current_user_email')->nullable();
            $table->unsignedBigInteger('current_user_custno')->nullable();
            $table->string('current_user_identity_type', 30)->nullable();
            $table->string('selected_game_intake_code', 80)->nullable();
            $table->string('origin')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('ip_address_v4', 45)->nullable();
            $table->string('ip_address_v6', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('timezone', 80)->nullable();
            $table->string('locale', 40)->nullable();
            $table->string('screen_size', 40)->nullable();
            $table->string('viewport_size', 40)->nullable();
            $table->json('notification_identities')->nullable();
            $table->unsignedInteger('notification_identity_count')->default(0);
            $table->json('payload')->nullable();
            $table->dateTime('client_sent_at')->nullable();
            $table->timestamps();

            $table->index(['event_type', 'created_at']);
            $table->index('current_user_email');
            $table->index('browser_uuid');
            $table->index('selected_game_intake_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('browser_identity_events');
        Schema::dropIfExists('browser_identities');
    }
};
