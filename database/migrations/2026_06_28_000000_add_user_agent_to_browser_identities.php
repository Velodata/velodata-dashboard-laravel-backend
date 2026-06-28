<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('browser_identities', function (Blueprint $table) {
            if (! Schema::hasColumn('browser_identities', 'user_agent')) {
                $table->text('user_agent')->nullable()->after('last_user_agent_hash');
            }
        });
    }

    public function down(): void
    {
        Schema::table('browser_identities', function (Blueprint $table) {
            if (Schema::hasColumn('browser_identities', 'user_agent')) {
                $table->dropColumn('user_agent');
            }
        });
    }
};