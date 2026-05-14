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
        if (! Schema::hasTable('user_notifications') || Schema::hasColumn('user_notifications', 'dismissed_at')) {
            return;
        }

        Schema::table('user_notifications', function (Blueprint $table) {
            $table->timestamp('dismissed_at')->nullable()->index()->after('read_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('user_notifications') || ! Schema::hasColumn('user_notifications', 'dismissed_at')) {
            return;
        }

        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropIndex(['dismissed_at']);
            $table->dropColumn('dismissed_at');
        });
    }
};
