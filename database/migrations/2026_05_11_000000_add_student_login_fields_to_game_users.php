<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('game_users', function (Blueprint $table) {
            if (! Schema::hasColumn('game_users', 'password')) {
                $table->string('password')->nullable()->after('email');
            }

            if (! Schema::hasColumn('game_users', 'must_change_password')) {
                $table->boolean('must_change_password')->default(true)->after('password');
            }

            if (! Schema::hasColumn('game_users', 'last_login_at')) {
                $table->dateTime('last_login_at')->nullable()->after('must_change_password');
            }
        });

        DB::table('game_users')->update([
            'password' => Hash::make('equinim01'),
            'must_change_password' => true,
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('game_users', function (Blueprint $table) {
            if (Schema::hasColumn('game_users', 'last_login_at')) {
                $table->dropColumn('last_login_at');
            }

            if (Schema::hasColumn('game_users', 'must_change_password')) {
                $table->dropColumn('must_change_password');
            }

            if (Schema::hasColumn('game_users', 'password')) {
                $table->dropColumn('password');
            }
        });
    }
};
