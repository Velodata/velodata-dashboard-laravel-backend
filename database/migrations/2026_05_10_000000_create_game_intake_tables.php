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
        Schema::create('game_intakes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name');
            $table->foreignId('trainer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('planned');
            $table->string('active_week', 30)->default('week_1');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'active_week']);
            $table->index('trainer_user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_system_user')->default(false)->after('remember_token');
            $table->boolean('is_game_user')->default(false)->after('is_system_user');
            $table->foreignId('home_intake_id')
                ->nullable()
                ->after('is_game_user')
                ->constrained('game_intakes')
                ->nullOnDelete();
            $table->dateTime('action_locked_until')->nullable()->after('home_intake_id');
            $table->string('action_locked_reason', 255)->nullable()->after('action_locked_until');
            $table->foreignId('action_locked_by_user_id')
                ->nullable()
                ->after('action_locked_reason')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['is_system_user', 'is_game_user']);
            $table->index('home_intake_id');
            $table->index('action_locked_until');
        });

        Schema::create('game_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_id')->constrained('game_intakes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('game_role', 50)->default('player');
            $table->string('game_status', 50)->default('active');
            $table->boolean('is_spy')->default(false);
            $table->boolean('is_protector')->default(false);
            $table->dateTime('eliminated_at')->nullable();
            $table->foreignId('eliminated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['intake_id', 'user_id']);
            $table->index(['intake_id', 'game_status']);
            $table->index(['intake_id', 'game_role']);
            $table->index(['is_spy', 'is_protector']);
        });

        Schema::create('game_baselines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_id')->constrained('game_intakes')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['intake_id', 'is_active']);
        });

        Schema::create('game_baseline_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('baseline_id')->constrained('game_baselines')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('profile_image')->nullable();
            $table->string('google_id')->nullable();
            $table->unsignedBigInteger('custno')->nullable();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->string('role_name')->nullable();
            $table->string('status')->nullable();
            $table->string('updated_by')->nullable();
            $table->string('company_name')->nullable();
            $table->string('gender', 50)->nullable();
            $table->string('location')->nullable();
            $table->string('phone_no', 50)->nullable();
            $table->string('address_1')->nullable();
            $table->string('address_2')->nullable();
            $table->string('address_3')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postcode', 20)->nullable();
            $table->string('game_role', 50)->nullable();
            $table->string('game_status', 50)->nullable();
            $table->boolean('is_spy')->default(false);
            $table->boolean('is_protector')->default(false);
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->unique(['baseline_id', 'user_id']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_baseline_users');
        Schema::dropIfExists('game_baselines');
        Schema::dropIfExists('game_players');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['home_intake_id']);
            $table->dropForeign(['action_locked_by_user_id']);
            $table->dropIndex(['is_system_user', 'is_game_user']);
            $table->dropIndex(['home_intake_id']);
            $table->dropIndex(['action_locked_until']);
            $table->dropColumn([
                'is_system_user',
                'is_game_user',
                'home_intake_id',
                'action_locked_until',
                'action_locked_reason',
                'action_locked_by_user_id',
            ]);
        });

        Schema::dropIfExists('game_intakes');
    }
};
