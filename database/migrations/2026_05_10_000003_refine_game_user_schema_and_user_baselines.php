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
        Schema::dropIfExists('game_baseline_users');
        Schema::dropIfExists('game_players');

        Schema::create('game_baseline_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('baseline_id')->constrained('game_baselines')->cascadeOnDelete();
            $table->foreignId('game_user_id')->constrained('game_users')->cascadeOnDelete();
            $table->string('first_name')->nullable();
            $table->string('surname')->nullable();
            $table->string('preferred_name')->nullable();
            $table->string('display_name')->nullable();
            $table->string('email')->nullable();
            $table->text('special_needs')->nullable();
            $table->string('game_role', 50)->nullable();
            $table->string('game_status', 50)->nullable();
            $table->boolean('is_spy')->default(false);
            $table->boolean('is_protector')->default(false);
            $table->dateTime('action_locked_until')->nullable();
            $table->string('action_locked_reason', 255)->nullable();
            $table->unsignedBigInteger('action_locked_by_game_user_id')->nullable();
            $table->dateTime('eliminated_at')->nullable();
            $table->unsignedBigInteger('eliminated_by_game_user_id')->nullable();
            $table->json('metadata')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->unique(['baseline_id', 'game_user_id']);
            $table->index('game_user_id');
        });

        Schema::create('user_table_baselines', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('user_table_baseline_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('baseline_id')->constrained('user_table_baselines')->cascadeOnDelete();
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
            $table->boolean('is_system_user')->default(true);
            $table->boolean('is_game_user')->default(false);
            $table->foreignId('home_intake_id')->nullable()->constrained('game_intakes')->nullOnDelete();
            $table->dateTime('action_locked_until')->nullable();
            $table->string('action_locked_reason', 255)->nullable();
            $table->foreignId('action_locked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
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
        Schema::dropIfExists('user_table_baseline_rows');
        Schema::dropIfExists('user_table_baselines');
        Schema::dropIfExists('game_baseline_users');

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

        Schema::create('game_baseline_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('baseline_id')->constrained('game_baselines')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->unique(['baseline_id', 'user_id']);
            $table->index('user_id');
        });
    }
};
