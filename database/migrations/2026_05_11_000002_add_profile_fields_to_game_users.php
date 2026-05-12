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
        Schema::table('game_users', function (Blueprint $table) {
            if (! Schema::hasColumn('game_users', 'profile_image')) {
                $table->text('profile_image')->nullable()->after('email');
            }

            if (! Schema::hasColumn('game_users', 'phone_no')) {
                $table->string('phone_no', 50)->nullable()->after('last_login_at');
            }

            if (! Schema::hasColumn('game_users', 'location')) {
                $table->string('location', 255)->nullable()->after('phone_no');
            }

            if (! Schema::hasColumn('game_users', 'city')) {
                $table->string('city', 120)->nullable()->after('location');
            }

            if (! Schema::hasColumn('game_users', 'state')) {
                $table->string('state', 120)->nullable()->after('city');
            }

            if (! Schema::hasColumn('game_users', 'postcode')) {
                $table->string('postcode', 20)->nullable()->after('state');
            }

            if (! Schema::hasColumn('game_users', 'updated_by')) {
                $table->string('updated_by', 255)->nullable()->after('postcode');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('game_users', function (Blueprint $table) {
            foreach (['updated_by', 'postcode', 'state', 'city', 'location', 'phone_no', 'profile_image'] as $column) {
                if (Schema::hasColumn('game_users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
