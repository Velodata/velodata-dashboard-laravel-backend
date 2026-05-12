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
        if (Schema::hasTable('user_notifications')) {
            return;
        }

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('recipient_email')->index();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_email')->nullable()->index();
            $table->string('type', 50)->default('info');
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('source', 100)->default('system');
            $table->unsignedInteger('related_audit_history_id')->nullable()->index();
            $table->string('dedupe_key')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['recipient_email', 'dedupe_key'], 'user_notifications_recipient_dedupe_unique');
            $table->index('created_at');

            if (Schema::hasTable('user_audit_history')) {
                $table->foreign('related_audit_history_id')
                    ->references('id')
                    ->on('user_audit_history')
                    ->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
