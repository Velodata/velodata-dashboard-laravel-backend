<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function settingDefinitions(): array
    {
        return [
            'security_manual_login_requires_2fa' => ['type' => 'boolean', 'group' => 'game-controls', 'label' => 'Manual login requires 2FA', 'sort' => 10],
            'security_block_banned_login' => ['type' => 'boolean', 'group' => 'game-controls', 'label' => 'Banned players cannot log in', 'sort' => 20],
            'security_protect_admin_account' => ['type' => 'boolean', 'group' => 'game-controls', 'label' => 'Protected Admin account cannot be edited, banned, or deleted', 'sort' => 30],
            'security_geo_lock_user_edits' => ['type' => 'boolean', 'group' => 'game-controls', 'label' => 'User edits must originate from Australia', 'sort' => 40],
            'security_require_audit_reason' => ['type' => 'boolean', 'group' => 'game-controls', 'label' => 'Dangerous user actions require an audit reason', 'sort' => 50],
            'security_log_protected_attempts' => ['type' => 'boolean', 'group' => 'game-controls', 'label' => 'Log protected-account attempts', 'sort' => 60],
            'security_notify_protected_attempts' => ['type' => 'boolean', 'group' => 'game-controls', 'label' => 'Notify Admins, Protectors, and Trainers about protected-account attempts', 'sort' => 70],
            'security_hide_protected_audits' => ['type' => 'boolean', 'group' => 'game-controls', 'label' => 'Hide protected security audits from normal users', 'sort' => 80],
            'game_allow_non_admin_add_users' => ['type' => 'boolean', 'group' => 'game-vulnerabilities', 'label' => 'Non-admin players can add users', 'sort' => 90],
            'game_allow_non_admin_choose_roles' => ['type' => 'boolean', 'group' => 'game-vulnerabilities', 'label' => 'Non-admin players can choose any role for new users', 'sort' => 100],
            'game_allow_oauth_role_selection' => ['type' => 'boolean', 'group' => 'game-vulnerabilities', 'label' => 'Google OAuth registration can create privileged roles', 'sort' => 110],
            'game_delete_cooldown_enabled' => ['type' => 'boolean', 'group' => 'elimination-recovery', 'label' => 'Delete attacker receives an action lock', 'sort' => 120],
            'game_delete_cooldown_minutes' => ['type' => 'integer', 'group' => 'elimination-recovery', 'label' => 'Delete timeout minutes', 'sort' => 130],
            'game_allow_undelete' => ['type' => 'boolean', 'group' => 'elimination-recovery', 'label' => 'Deleted players can be restored by defenders', 'sort' => 140],
            'game_protector_spy_controls' => ['type' => 'boolean', 'group' => 'roles-spies', 'label' => 'Protector spy controls are enabled', 'sort' => 150],
            'game_protector_spy_visibility' => ['type' => 'boolean', 'group' => 'roles-spies', 'label' => 'Protectors can identify spies', 'sort' => 155],
            'game_protector_actor_impersonation' => ['type' => 'boolean', 'group' => 'roles-spies', 'label' => 'Protectors can now appear as other users', 'sort' => 157],
            'game_spy_audit_impersonation' => ['type' => 'boolean', 'group' => 'roles-spies', 'label' => 'Spies can appear as other users in audit screens', 'sort' => 160],
            'game_account_drill_down_counterattack_enabled' => ['type' => 'boolean', 'group' => 'roles-spies', 'label' => 'Admins and Protectors can Counterattack from Account Drill Downs', 'sort' => 166],
            'game_last_man_standing_enabled' => ['type' => 'boolean', 'group' => 'elimination-recovery', 'label' => 'Winner is the last active eligible player', 'sort' => 170],
            'game_auto_detect_winner' => ['type' => 'boolean', 'group' => 'elimination-recovery', 'label' => 'Automatically detect a winner when one player remains', 'sort' => 180],
            'game_baseline_reset_enabled' => ['type' => 'boolean', 'group' => 'elimination-recovery', 'label' => 'Class baseline reset is enabled', 'sort' => 190],
        ];
    }

    private function weekDefaults(): array
    {
        return [
            'week_1' => [
                'security_manual_login_requires_2fa' => '0',
                'security_block_banned_login' => '0',
                'security_protect_admin_account' => '0',
                'security_geo_lock_user_edits' => '0',
                'security_require_audit_reason' => '0',
                'security_log_protected_attempts' => '0',
                'security_notify_protected_attempts' => '0',
                'security_hide_protected_audits' => '0',
                'game_allow_non_admin_add_users' => '1',
                'game_allow_non_admin_choose_roles' => '1',
                'game_allow_oauth_role_selection' => '1',
                'game_delete_cooldown_enabled' => '0',
                'game_allow_undelete' => '0',
                'game_protector_spy_controls' => '0',
                'game_protector_spy_visibility' => '0',
                'game_protector_actor_impersonation' => '0',
                'game_spy_audit_impersonation' => '0',
                'game_account_drill_down_counterattack_enabled' => '0',
                'game_last_man_standing_enabled' => '1',
                'game_auto_detect_winner' => '0',
                'game_baseline_reset_enabled' => '1',
            ],
            'week_2' => [
                'security_require_audit_reason' => '1',
                'security_log_protected_attempts' => '1',
                'game_delete_cooldown_enabled' => '1',
                'game_allow_undelete' => '1',
            ],
            'week_3' => [
                'security_block_banned_login' => '1',
                'security_protect_admin_account' => '1',
                'security_require_audit_reason' => '1',
                'security_log_protected_attempts' => '1',
                'security_notify_protected_attempts' => '1',
                'game_allow_non_admin_add_users' => '1',
                'game_delete_cooldown_enabled' => '1',
                'game_allow_undelete' => '1',
                'game_protector_spy_controls' => '1',
                'game_protector_spy_visibility' => '1',
                'game_protector_actor_impersonation' => '1',
                'game_account_drill_down_counterattack_enabled' => '1',
                'game_last_man_standing_enabled' => '1',
                'game_auto_detect_winner' => '1',
                'game_baseline_reset_enabled' => '1',
            ],
            'week_4' => [
                'security_block_banned_login' => '1',
                'security_protect_admin_account' => '1',
                'security_geo_lock_user_edits' => '1',
                'security_require_audit_reason' => '1',
                'security_log_protected_attempts' => '1',
                'security_notify_protected_attempts' => '1',
                'security_hide_protected_audits' => '1',
                'game_allow_non_admin_add_users' => '1',
                'game_delete_cooldown_enabled' => '1',
                'game_allow_undelete' => '1',
                'game_protector_spy_controls' => '1',
                'game_protector_spy_visibility' => '1',
                'game_protector_actor_impersonation' => '1',
                'game_last_man_standing_enabled' => '1',
                'game_auto_detect_winner' => '1',
                'game_baseline_reset_enabled' => '1',
            ],
            'week_5' => [
                'security_manual_login_requires_2fa' => '1',
                'security_block_banned_login' => '1',
                'security_protect_admin_account' => '1',
                'security_geo_lock_user_edits' => '1',
                'security_require_audit_reason' => '1',
                'security_log_protected_attempts' => '1',
                'security_notify_protected_attempts' => '1',
                'security_hide_protected_audits' => '1',
                'game_delete_cooldown_enabled' => '1',
                'game_allow_undelete' => '1',
                'game_protector_spy_controls' => '1',
                'game_protector_spy_visibility' => '1',
                'game_protector_actor_impersonation' => '1',
                'game_spy_audit_impersonation' => '1',
                'game_account_drill_down_counterattack_enabled' => '1',
                'game_last_man_standing_enabled' => '1',
                'game_auto_detect_winner' => '1',
                'game_baseline_reset_enabled' => '1',
            ],
            'week_6' => [
                'security_manual_login_requires_2fa' => '1',
                'security_block_banned_login' => '1',
                'security_protect_admin_account' => '1',
                'security_geo_lock_user_edits' => '1',
                'security_require_audit_reason' => '1',
                'security_log_protected_attempts' => '1',
                'security_notify_protected_attempts' => '1',
                'security_hide_protected_audits' => '1',
                'game_delete_cooldown_enabled' => '1',
                'game_allow_undelete' => '1',
                'game_protector_spy_controls' => '1',
                'game_protector_spy_visibility' => '1',
                'game_protector_actor_impersonation' => '1',
                'game_spy_audit_impersonation' => '1',
                'game_account_drill_down_counterattack_enabled' => '1',
                'game_last_man_standing_enabled' => '1',
                'game_auto_detect_winner' => '1',
                'game_baseline_reset_enabled' => '1',
            ],
        ];
    }

    public function up(): void
    {
        Schema::create('game_intake_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_intake_id')->constrained('game_intakes')->cascadeOnDelete();
            $table->string('key', 120);
            $table->text('value')->nullable();
            $table->string('type', 30)->default('string');
            $table->string('group', 80)->nullable();
            $table->string('label')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['game_intake_id', 'key']);
            $table->index(['game_intake_id', 'group']);
        });

        $definitions = $this->settingDefinitions();
        $weekOne = $this->weekDefaults()['week_1'];
        $weekDefaults = $this->weekDefaults();
        $now = now();

        DB::table('game_intakes')->orderBy('id')->get(['id', 'active_week'])->each(function ($intake) use ($definitions, $weekOne, $weekDefaults, $now) {
            $activeWeek = $intake->active_week ?: 'week_1';
            $defaults = array_merge($weekOne, $weekDefaults[$activeWeek] ?? []);

            foreach ($definitions as $key => $definition) {
                DB::table('game_intake_settings')->insert([
                    'game_intake_id' => $intake->id,
                    'key' => $key,
                    'value' => $defaults[$key] ?? ($definition['type'] === 'integer' ? '5' : '0'),
                    'type' => $definition['type'],
                    'group' => $definition['group'],
                    'label' => $definition['label'],
                    'description' => null,
                    'sort_order' => $definition['sort'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_intake_settings');
    }
};
