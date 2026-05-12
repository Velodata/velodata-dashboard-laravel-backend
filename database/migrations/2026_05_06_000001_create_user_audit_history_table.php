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
        if (Schema::hasTable('user_audit_history')) {
            return;
        }

        Schema::create('user_audit_history', function (Blueprint $table) {
            $table->increments('id');
            $table->string('custno', 8);
            $table->dateTime('dteprfmd')->nullable();
            $table->text('comments')->nullable();
            $table->text('clerk_id')->nullable();
            $table->text('created_by_email')->nullable();
            $table->string('created_by_ip_address', 45)->nullable();
            $table->timestamps();

            $table->index('custno', 'idx_custno');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_audit_history');
    }
};
