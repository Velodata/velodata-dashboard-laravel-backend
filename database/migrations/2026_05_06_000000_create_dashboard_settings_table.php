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
        Schema::create('dashboard_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type', 50)->default('string');
            $table->string('group', 100)->default('global');
            $table->string('label');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_public')->default(false);
            $table->timestamps();
        });

        DB::table('dashboard_settings')->insert([
            [
                'key' => 'login_2fa_enabled',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'security',
                'label' => 'Turn on 2FA during the login process?',
                'description' => 'Controls whether manual dashboard logins must complete a 2FA email code challenge.',
                'sort_order' => 10,
                'is_public' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'login_2fa_send_to_account',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'security',
                'label' => 'Send 2FA Login emails to the account logging in?',
                'description' => 'Controls whether the 2FA login code is emailed to the user account that is signing in.',
                'sort_order' => 20,
                'is_public' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'login_2fa_send_to_master',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'security',
                'label' => 'Send 2FA Login email copies to the Master email account?',
                'description' => 'Controls whether a copy of each 2FA login code is emailed to the configured master email account.',
                'sort_order' => 30,
                'is_public' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'login_2fa_master_email',
                'value' => 'ivanvetsich@gmail.com',
                'type' => 'email',
                'group' => 'security',
                'label' => 'Master email account',
                'description' => 'Receives 2FA login email copies when master email copies are enabled.',
                'sort_order' => 40,
                'is_public' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dashboard_settings');
    }
};
