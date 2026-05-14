<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_presence')) {
            return;
        }

        Schema::table('user_presence', function (Blueprint $table) {
            if (! Schema::hasColumn('user_presence', 'ip_address_v4')) {
                $table->string('ip_address_v4', 45)->nullable()->after('ip_address');
            }

            if (! Schema::hasColumn('user_presence', 'ip_address_v6')) {
                $table->string('ip_address_v6', 45)->nullable()->after('ip_address_v4');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_presence')) {
            return;
        }

        Schema::table('user_presence', function (Blueprint $table) {
            if (Schema::hasColumn('user_presence', 'ip_address_v6')) {
                $table->dropColumn('ip_address_v6');
            }

            if (Schema::hasColumn('user_presence', 'ip_address_v4')) {
                $table->dropColumn('ip_address_v4');
            }
        });
    }
};
