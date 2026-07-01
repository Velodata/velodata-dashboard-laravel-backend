<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('browser_identities', function (Blueprint $table) {
            if (! Schema::hasColumn('browser_identities', 'client_hints')) {
                $table->json('client_hints')->nullable()->after('user_agent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('browser_identities', function (Blueprint $table) {
            if (Schema::hasColumn('browser_identities', 'client_hints')) {
                $table->dropColumn('client_hints');
            }
        });
    }
};
