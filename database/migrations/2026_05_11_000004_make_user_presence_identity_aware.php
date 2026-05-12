<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_presence', function (Blueprint $table) {
            if (! Schema::hasColumn('user_presence', 'identity_type')) {
                $table->string('identity_type', 30)->default('staff')->after('id');
            }

            if (! Schema::hasColumn('user_presence', 'game_user_id')) {
                $table->unsignedBigInteger('game_user_id')->nullable()->after('user_id');
            }

            if (! Schema::hasColumn('user_presence', 'presence_key')) {
                $table->string('presence_key', 80)->nullable()->after('game_user_id');
            }
        });

        try {
            Schema::table('user_presence', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        } catch (\Throwable $error) {
            // Some local databases may already have this foreign key removed.
        }

        try {
            Schema::table('user_presence', function (Blueprint $table) {
                $table->dropUnique(['user_id']);
            });
        } catch (\Throwable $error) {
            // Some local databases may already have this unique index removed.
        }

        DB::statement('ALTER TABLE user_presence MODIFY user_id BIGINT UNSIGNED NULL');

        DB::table('user_presence')
            ->whereNull('presence_key')
            ->update([
                'identity_type' => 'staff',
                'presence_key' => DB::raw("CONCAT('staff:', user_id)"),
            ]);

        Schema::table('user_presence', function (Blueprint $table) {
            $table->unique('presence_key');
            $table->index(['identity_type', 'user_id']);
            $table->index(['identity_type', 'game_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_presence', function (Blueprint $table) {
            try {
                $table->dropUnique(['presence_key']);
            } catch (\Throwable $error) {
            }

            try {
                $table->dropIndex(['identity_type', 'user_id']);
            } catch (\Throwable $error) {
            }

            try {
                $table->dropIndex(['identity_type', 'game_user_id']);
            } catch (\Throwable $error) {
            }
        });

        Schema::table('user_presence', function (Blueprint $table) {
            foreach (['presence_key', 'game_user_id', 'identity_type'] as $column) {
                if (Schema::hasColumn('user_presence', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
