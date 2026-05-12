<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();
        $settings = [
            [
                'key' => 'game_active_week',
                'value' => 'week_1',
                'type' => 'string',
                'group' => 'game',
                'label' => 'Active course security week',
                'description' => 'Controls which weekly security preset is currently active for the cyber game.',
                'sort_order' => 100,
            ],
            [
                'key' => 'game_active_intake_id',
                'value' => null,
                'type' => 'integer',
                'group' => 'game',
                'label' => 'Active intake',
                'description' => 'Stores the current class/intake selected for game management.',
                'sort_order' => 110,
            ],
            [
                'key' => 'security_protect_admin_account',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'game_security',
                'label' => 'Protect Admin account from edit, ban, or delete',
                'description' => 'Blocks game actions from changing protected system/Admin accounts.',
                'sort_order' => 200,
            ],
            [
                'key' => 'security_geo_lock_user_edits',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'game_security',
                'label' => 'Require user edits to originate from Australia',
                'description' => 'Blocks user update actions when the requester IP geolocates outside Australia.',
                'sort_order' => 210,
            ],
            [
                'key' => 'security_block_banned_login',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'game_security',
                'label' => 'Block banned users from login',
                'description' => 'Prevents accounts marked BANNED from signing in during the game.',
                'sort_order' => 220,
            ],
            [
                'key' => 'security_require_audit_reason',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'game_security',
                'label' => 'Require audit reason for dangerous actions',
                'description' => 'Requires a reason when users are edited, banned, deleted, restored, or reset.',
                'sort_order' => 230,
            ],
            [
                'key' => 'security_log_protected_attempts',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'game_security',
                'label' => 'Log protected-account attempts',
                'description' => 'Creates audit records when a protected account action is blocked.',
                'sort_order' => 240,
            ],
            [
                'key' => 'security_notify_protected_attempts',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'game_security',
                'label' => 'Notify Admins and Protectors about protected-account attempts',
                'description' => 'Creates warning notifications for defenders when protected accounts are targeted.',
                'sort_order' => 250,
            ],
            [
                'key' => 'security_hide_protected_audits',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'game_security',
                'label' => 'Hide protected security audits from normal users',
                'description' => 'Limits sensitive security audit entries to Admin and Protector roles.',
                'sort_order' => 260,
            ],
            [
                'key' => 'game_allow_non_admin_add_users',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'game_vulnerabilities',
                'label' => 'Allow non-admin players to add users',
                'description' => 'Keeps the intentional early-game vulnerability where ordinary players can create users.',
                'sort_order' => 300,
            ],
            [
                'key' => 'game_allow_non_admin_choose_roles',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'game_vulnerabilities',
                'label' => 'Allow non-admin players to choose new user roles',
                'description' => 'Allows created users to receive elevated roles as part of the game vulnerability.',
                'sort_order' => 310,
            ],
            [
                'key' => 'game_allow_oauth_role_selection',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'game_vulnerabilities',
                'label' => 'Allow Google OAuth registration to choose roles',
                'description' => 'Extends the role-selection vulnerability to Google OAuth-created users.',
                'sort_order' => 320,
            ],
            [
                'key' => 'game_oauth_default_role_id',
                'value' => '2',
                'type' => 'integer',
                'group' => 'game_vulnerabilities',
                'label' => 'Google OAuth default role ID',
                'description' => 'Role ID assigned to new OAuth users when OAuth role selection is disabled.',
                'sort_order' => 330,
            ],
            [
                'key' => 'game_delete_cooldown_enabled',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'game_elimination',
                'label' => 'Lock attacker after deleting a user',
                'description' => 'Temporarily prevents the deleting player from taking further dangerous actions.',
                'sort_order' => 400,
            ],
            [
                'key' => 'game_delete_cooldown_minutes',
                'value' => '5',
                'type' => 'integer',
                'group' => 'game_elimination',
                'label' => 'Delete cooldown minutes',
                'description' => 'Number of minutes a player is action-locked after deleting another player.',
                'sort_order' => 410,
            ],
            [
                'key' => 'game_allow_undelete',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'game_elimination',
                'label' => 'Allow deleted users to be restored',
                'description' => 'Enables defender recovery for users marked DELETED.',
                'sort_order' => 420,
            ],
            [
                'key' => 'game_undelete_roles',
                'value' => 'Admin,Protector',
                'type' => 'csv',
                'group' => 'game_elimination',
                'label' => 'Roles allowed to undelete users',
                'description' => 'Comma-separated role names that may restore deleted users when undelete is enabled.',
                'sort_order' => 430,
            ],
            [
                'key' => 'game_last_man_standing_enabled',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'game_elimination',
                'label' => 'Use last-man-standing win condition',
                'description' => 'Treats the last active eligible player as the game winner.',
                'sort_order' => 440,
            ],
            [
                'key' => 'game_auto_detect_winner',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'game_elimination',
                'label' => 'Automatically detect winner',
                'description' => 'Detects a winner when one active eligible player remains in the intake.',
                'sort_order' => 450,
            ],
            [
                'key' => 'game_protector_spy_controls',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'game_roles',
                'label' => 'Only Protectors can see, ban, or delete spies',
                'description' => 'Restricts spy visibility and spy elimination actions to Protector users.',
                'sort_order' => 500,
            ],
            [
                'key' => 'game_spy_audit_impersonation',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'game_roles',
                'label' => 'Allow spies to appear as other users in audit screens',
                'description' => 'Allows spy actions to show a displayed actor that differs from the real actor.',
                'sort_order' => 510,
            ],
            [
                'key' => 'game_baseline_reset_enabled',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'game_reset',
                'label' => 'Enable intake baseline reset',
                'description' => 'Allows an intake to be restored to a known baseline without restoring the whole users table.',
                'sort_order' => 600,
            ],
        ];

        foreach ($settings as $setting) {
            DB::table('dashboard_settings')->updateOrInsert(
                ['key' => $setting['key']],
                [
                    'value' => $setting['value'],
                    'type' => $setting['type'],
                    'group' => $setting['group'],
                    'label' => $setting['label'],
                    'description' => $setting['description'],
                    'sort_order' => $setting['sort_order'],
                    'is_public' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('dashboard_settings')
            ->whereIn('key', [
                'game_active_week',
                'game_active_intake_id',
                'security_protect_admin_account',
                'security_geo_lock_user_edits',
                'security_block_banned_login',
                'security_require_audit_reason',
                'security_log_protected_attempts',
                'security_notify_protected_attempts',
                'security_hide_protected_audits',
                'game_allow_non_admin_add_users',
                'game_allow_non_admin_choose_roles',
                'game_allow_oauth_role_selection',
                'game_oauth_default_role_id',
                'game_delete_cooldown_enabled',
                'game_delete_cooldown_minutes',
                'game_allow_undelete',
                'game_undelete_roles',
                'game_last_man_standing_enabled',
                'game_auto_detect_winner',
                'game_protector_spy_controls',
                'game_spy_audit_impersonation',
                'game_baseline_reset_enabled',
            ])
            ->delete();
    }
};
