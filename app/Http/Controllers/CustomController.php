<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;


use App\Mail\Test2FAMail;
use App\Mail\TwoFactorCodeMail;
use Illuminate\Support\Facades\Mail;
// IJV - 2025.05.30 - Added to allow for 2FA verification emails



use Illuminate\Support\Facades\Log;
// IJV - 2025.06.15 - Added to allow for improved Role permissions logic



use App\Models\User; // Your User Model
use App\Models\UserLogin; // Your User Login Model
use App\Models\GameUser;
use Carbon\Carbon;
use App\Models\Role;


class CustomController extends Controller
{
    private function dashboardSettingValue(string $key, $default = null)
    {
        $setting = DB::table('dashboard_settings')->where('key', $key)->first();

        return $setting ? $setting->value : $default;
    }

    private function dashboardSettingBoolean(string $key, bool $default = false): bool
    {
        $value = $this->dashboardSettingValue($key, $default ? '1' : '0');

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function dashboardSettingInteger(string $key, int $default = 0): int
    {
        $value = $this->dashboardSettingValue($key, (string) $default);

        return is_numeric($value) ? intval($value) : $default;
    }

    private function intakeGameSettingValue(?int $intakeId, string $key, $default = null)
    {
        if (! $intakeId || ! Schema::hasTable('game_intake_settings')) {
            return $default;
        }

        $setting = DB::table('game_intake_settings')
            ->where('game_intake_id', $intakeId)
            ->where('key', $key)
            ->first();

        return $setting ? $setting->value : $default;
    }

    private function intakeGameSettingBoolean(?int $intakeId, string $key, bool $default = false): bool
    {
        $value = $this->intakeGameSettingValue($intakeId, $key, $default ? '1' : '0');

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function intakeGameSettingInteger(?int $intakeId, string $key, int $default = 0): int
    {
        $value = $this->intakeGameSettingValue($intakeId, $key, (string) $default);

        return is_numeric($value) ? intval($value) : $default;
    }

    private function gameUserSettingBoolean($gameUser, string $key, bool $default = false): bool
    {
        $intakeId = $gameUser?->intake_id ? intval($gameUser->intake_id) : null;

        return $this->intakeGameSettingBoolean($intakeId, $key, $default);
    }

    private function paneOneBlocksBannedLogin($gameUser): bool
    {
        return $this->gameUserSettingBoolean($gameUser, 'security_block_banned_login', false);
    }

    private function isLocalOrPrivateIp(?string $ip): bool
    {
        if (! $ip) {
            return false;
        }

        return in_array($ip, ['127.0.0.1', '::1', '0:0:0:0:0:0:0:1', 'localhost'], true)
            || str_starts_with($ip, '192.168.')
            || str_starts_with($ip, '10.')
            || preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $ip);
    }

    private function getLocationLookupIp(Request $request): string
    {
        $clientReportedIpV4 = $this->normalizeIpAddress($request->input('vmd_ip_address_v4'));
        if ($clientReportedIpV4 && $clientReportedIpV4['ip_address_v4']) {
            return $clientReportedIpV4['ip_address_v4'];
        }

        $clientReportedIpV6 = $this->normalizeIpAddress($request->input('vmd_ip_address_v6'));
        if ($clientReportedIpV6 && $clientReportedIpV6['ip_address_v6']) {
            return $clientReportedIpV6['ip_address_v6'];
        }

        $resolved = $this->resolveClientIpAddresses($request);

        return $resolved['ip_address_v4'] ?: $resolved['ip_address_v6'] ?: $resolved['ip_address'] ?: $request->ip();
    }

    private function countryCodeForRequest(Request $request, string $context): array
    {
        if (app()->environment('testing') && $request->filled('vmd_test_country_code')) {
            return ['country' => strtoupper((string) $request->input('vmd_test_country_code')), 'error' => null];
        }

        $lookupIp = trim($this->getLocationLookupIp($request));

        if ($this->isLocalOrPrivateIp($lookupIp)) {
            return ['country' => 'AU', 'error' => null];
        }

        $accessToken = '4af1c2308a696c';
        $apiUrl = "http://ipinfo.io/{$lookupIp}/json?token={$accessToken}";
        $pageContent = file_get_contents($apiUrl);

        if ($pageContent === false) {
            return [
                'country' => null,
                'error' => response()->json([
                    'errors' => "Failed to fetch geolocation data during {$context} for {$lookupIp}.",
                ], 500),
            ];
        }

        $parsedJson = json_decode($pageContent);

        return ['country' => $parsedJson->country ?? null, 'error' => null];
    }

    private function geoLockUserEditResponse(Request $request, ?int $intakeId, string $context)
    {
        if (! $intakeId || ! $this->intakeGameSettingBoolean($intakeId, 'security_geo_lock_user_edits', false)) {
            return null;
        }

        $location = $this->countryCodeForRequest($request, $context);
        if ($location['error']) {
            return $location['error'];
        }

        if ($location['country'] !== 'AU') {
            return response()->json([
                'errors' => 'Permission Denied:  (You can only perform updates if you are in Australia)',
            ], 403);
        }

        return null;
    }

    private function userManagementTimeoutResponse(?string $email)
    {
        if (! $email) {
            return null;
        }

        $gameUser = GameUser::where('email', $email)->first();
        if (! $gameUser || ! $gameUser->action_locked_until) {
            return null;
        }

        $lockedUntil = Carbon::parse($gameUser->action_locked_until);
        if (! $lockedUntil->isFuture()) {
            return null;
        }

        $message = 'You are currently in a timeout period because you banned or deleted another user.';

        $this->createPersistedNotification([
            'recipient_email' => $gameUser->email,
            'actor_email' => $gameUser->email,
            'type' => 'warning',
            'title' => 'User Management timeout',
            'message' => $message,
            'source' => 'user-management',
            'metadata' => [
                'action_locked_until' => $lockedUntil->toDateTimeString(),
                'reason' => $gameUser->action_locked_reason,
            ],
        ]);

        return response()->json([
            'outcome' => 'FAIL',
            'message' => $message,
            'action_locked_until' => $lockedUntil->toDateTimeString(),
        ], 423);
    }

    private function applyActionCooldownIfNeeded(?string $actorEmail, object $targetGameUser, bool $targetWasActive, string $action): ?Carbon
    {
        if (intval($targetGameUser->id) === intval(GameUser::where('email', $actorEmail)->value('id'))) {
            return null;
        }

        return $this->lockActorGameUserIfNeeded(
            $actorEmail,
            $targetWasActive,
            ucfirst(strtolower($action)) . ' a fellow user',
            intval($targetGameUser->id)
        );
    }

    private function lockActorGameUserIfNeeded(
        ?string $actorEmail,
        bool $targetWasActive,
        string $reason,
        ?int $targetGameUserId = null
    ): ?Carbon {
        $actorGameUser = GameUser::where('email', $actorEmail)->first();
        if (! $actorGameUser) {
            return null;
        }

        $intakeId = $actorGameUser->intake_id ? intval($actorGameUser->intake_id) : null;
        $cooldownEnabled = $this->intakeGameSettingBoolean(
            $intakeId,
            'game_delete_cooldown_enabled',
            $this->dashboardSettingBoolean('game_delete_cooldown_enabled', false)
        );

        if (! $targetWasActive || ! $cooldownEnabled) {
            return null;
        }

        $minutes = max(0, $this->intakeGameSettingInteger(
            $intakeId,
            'game_delete_cooldown_minutes',
            $this->dashboardSettingInteger('game_delete_cooldown_minutes', 5)
        ));
        if ($minutes === 0) {
            return null;
        }

        $lockedUntil = now()->addMinutes($minutes);
        $actorGameUser->action_locked_until = $lockedUntil;
        $actorGameUser->action_locked_reason = $reason;
        $actorGameUser->action_locked_by_game_user_id = $targetGameUserId;
        $actorGameUser->save();

        $this->createPersistedNotification([
            'recipient_email' => $actorGameUser->email,
            'actor_email' => $actorGameUser->email,
            'type' => 'warning',
            'title' => 'User Management timeout',
            'message' => "You are in timeout for {$minutes} minutes, until {$lockedUntil->toDateTimeString()}, because you banned or deleted another user.",
            'source' => 'user-management',
            'dedupe_key' => 'game-user-timeout-' . intval($actorGameUser->id) . '-' . $lockedUntil->format('YmdHis'),
            'metadata' => [
                'game_user_id' => intval($actorGameUser->id),
                'identity_type' => 'student',
                'duration_minutes' => $minutes,
                'action_locked_until' => $lockedUntil->toDateTimeString(),
                'reason' => $reason,
                'target_game_user_id' => $targetGameUserId,
            ],
        ]);

        return $lockedUntil;
    }

    private function dashboardSettingRows()
    {
        return DB::table('dashboard_settings')
            ->orderBy('group')
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get()
            ->map(function ($setting) {
                $value = $setting->value;

                if ($setting->type === 'boolean') {
                    $value = filter_var($setting->value, FILTER_VALIDATE_BOOLEAN);
                }

                return [
                    'id' => $setting->id,
                    'key' => $setting->key,
                    'value' => $value,
                    'type' => $setting->type,
                    'group' => $setting->group,
                    'label' => $setting->label,
                    'description' => $setting->description,
                    'sort_order' => $setting->sort_order,
                    'is_public' => (bool) $setting->is_public,
                ];
            });
    }

    private function isAdminEmail(?string $email): bool
    {
        if (!$email) {
            return false;
        }

        $user = User::where('email', $email)->first();

        if (!$user) {
            return false;
        }

        if (strcasecmp((string) $user->role_name, 'Admin') === 0) {
            return true;
        }

        $role = $user->roles()->first();

        return $role && strcasecmp((string) $role->name, 'Admin') === 0;
    }

    private function isStaffRoleEmail(?string $email, array $allowedRoles): bool
    {
        if (!$email) {
            return false;
        }

        $user = User::where('email', $email)->first();

        if (!$user) {
            return false;
        }

        $roleName = strtolower($this->staffRoleName($user));
        $allowedRoles = array_map('strtolower', $allowedRoles);

        return in_array($roleName, $allowedRoles, true);
    }

    private function canAccessGlobalManagement(?string $email): bool
    {
        return $this->isStaffRoleEmail($email, ['Admin', 'Trainer']);
    }

    private function canManageGameUsers(?string $email): bool
    {
        if (! $email) {
            return false;
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            $roleName = $user->role_name ?: optional($user->roles()->first())->name;

            return in_array(strtolower((string) $roleName), ['admin', 'protector', 'trainer'], true);
        }

        $gameUser = GameUser::where('email', $email)->first();
        $roleName = $gameUser?->game_role;

        return in_array(strtolower((string) $roleName), ['admin', 'protector', 'spy'], true);
    }

    private function canViewAccountDrillDown(?string $email): bool
    {
        if (! $email) {
            return false;
        }

        $user = User::where('email', $email)->first();
        if ($user && strcasecmp($this->staffRoleName($user), 'Admin') === 0) {
            return true;
        }

        $gameUser = $this->actorGameUser($email);

        return $gameUser && strcasecmp((string) $gameUser->game_role, 'Admin') === 0;
    }

    private function accountDrillDownIntakeId(Request $request, ?array $target): ?int
    {
        $requestedIntakeCode = $request->input('game_intake_code')
            ? trim((string) $request->input('game_intake_code'))
            : null;

        if ($requestedIntakeCode) {
            $intakeId = DB::table('game_intakes')->where('code', $requestedIntakeCode)->value('id');

            return $intakeId ? (int) $intakeId : null;
        }

        $targetIntakeCode = $target['game_intake_code'] ?? null;
        if ($targetIntakeCode) {
            $intakeId = DB::table('game_intakes')->where('code', $targetIntakeCode)->value('id');

            return $intakeId ? (int) $intakeId : null;
        }

        return null;
    }

    private function accountDrillDownEnabledForRequest(Request $request, ?array $target): bool
    {
        $intakeId = $this->accountDrillDownIntakeId($request, $target);

        return $this->intakeGameSettingBoolean($intakeId, 'game_account_drill_down_enabled', false);
    }

    private function canViewNotificationActorDrillDown(?string $viewerEmail, ?string $targetEmail): bool
    {
        if (! $viewerEmail || ! $targetEmail) {
            return false;
        }

        return DB::table('user_notifications')
            ->where('recipient_email', $viewerEmail)
            ->where('actor_email', $targetEmail)
            ->whereNull('dismissed_at')
            ->exists();
    }

    private function canViewSpyAccountDrillDownRows(?string $email): bool
    {
        $staffUser = $email ? User::where('email', $email)->first() : null;
        if ($staffUser && strcasecmp($this->staffRoleName($staffUser), 'Protector') === 0) {
            return true;
        }

        $gameUser = $this->actorGameUser($email);

        return $gameUser && strcasecmp((string) $gameUser->game_role, 'Protector') === 0;
    }

    private function accountDrillDownNodeFromUser(User $user): array
    {
        return [
            'identity_type' => 'staff',
            'id' => (int) $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'role_name' => $this->staffRoleName($user),
            'status' => $user->status,
            'created_by_email' => $user->created_by_email,
            'created_at' => optional($user->created_at)->toDateTimeString(),
            'game_intake_code' => null,
            'game_intake_name' => null,
            'is_spy' => strcasecmp($this->staffRoleName($user), 'Spy') === 0,
        ];
    }

    private function accountDrillDownNodeFromGameUser(GameUser $gameUser): array
    {
        $intake = $gameUser->intake_id
            ? DB::table('game_intakes')->where('id', $gameUser->intake_id)->first()
            : null;

        return [
            'identity_type' => 'student',
            'id' => (int) $gameUser->id,
            'email' => $gameUser->email,
            'name' => $gameUser->display_name ?: trim(($gameUser->preferred_name ?: $gameUser->first_name) . ' ' . $gameUser->surname),
            'role_name' => $gameUser->game_role,
            'status' => strtoupper((string) $gameUser->game_status),
            'created_by_email' => $gameUser->created_by_email,
            'created_at' => optional($gameUser->created_at)->toDateTimeString(),
            'game_intake_code' => $intake->code ?? null,
            'game_intake_name' => $intake->name ?? null,
            'is_spy' => strcasecmp((string) $gameUser->game_role, 'Spy') === 0 || (bool) $gameUser->is_spy,
        ];
    }

    private function redactedSpyAccountDrillDownNode(): array
    {
        return [
            'identity_type' => 'hidden',
            'id' => null,
            'email' => null,
            'name' => 'Hidden Spy account',
            'role_name' => 'Spy',
            'status' => null,
            'created_by_email' => null,
            'created_at' => null,
            'game_intake_code' => null,
            'game_intake_name' => null,
            'is_spy' => true,
            'redacted' => true,
        ];
    }

    private function accountDrillDownTargetFromRequest(Request $request): ?array
    {
        $identityType = strtolower((string) $request->input('target_identity_type'));
        $targetId = $request->input('target_id');
        $targetEmail = $request->input('target_email');

        if ($identityType === 'student' && $targetId) {
            $gameUser = GameUser::find($targetId);

            return $gameUser ? $this->accountDrillDownNodeFromGameUser($gameUser) : null;
        }

        if ($identityType === 'staff' && $targetId) {
            $user = User::find($targetId);

            return $user ? $this->accountDrillDownNodeFromUser($user) : null;
        }

        if ($targetEmail) {
            return $this->accountDrillDownNodeByEmail($targetEmail);
        }

        return null;
    }

    private function accountDrillDownNodeByEmail(?string $email): ?array
    {
        if (! $email) {
            return null;
        }

        $gameUser = GameUser::where('email', $email)->first();
        if ($gameUser) {
            return $this->accountDrillDownNodeFromGameUser($gameUser);
        }

        $user = User::where('email', $email)->first();

        return $user ? $this->accountDrillDownNodeFromUser($user) : null;
    }

    public function F0_VMD_get_account_drill_down(Request $request)
    {
        $viewerEmail = $request->input('vmd_user_email');

        $validator = Validator::make($request->all(), [
            'vmd_user_email' => 'required|email',
            'game_intake_code' => 'nullable|string|exists:game_intakes,code',
            'target_email' => 'nullable|email',
            'target_identity_type' => 'nullable|string|in:staff,student',
            'target_id' => 'nullable|integer',
            'context' => 'nullable|string|in:user_management,user_notifications',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Validation failed.',
                'errors' => $validator->errors()->toArray(),
            ], 409);
        }

        $targetEmail = $request->input('target_email');
        $context = $request->input('context') ?: 'user_management';
        $canViewNotificationActor = $context === 'user_notifications'
            && $this->canViewNotificationActorDrillDown($viewerEmail, $targetEmail);
        $target = $this->accountDrillDownTargetFromRequest($request);

        if (! $target) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Account not found.',
            ], 404);
        }

        $requestedIntakeCode = $request->input('game_intake_code')
            ? trim((string) $request->input('game_intake_code'))
            : null;
        if (
            $requestedIntakeCode &&
            ($target['game_intake_code'] ?? null) &&
            $requestedIntakeCode !== $target['game_intake_code']
        ) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Permission Denied: target account is not in the requested Class Intake.',
            ], 403);
        }

        if (! $this->canViewAccountDrillDown($viewerEmail)) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Permission Denied: account drill down requires Admin access.',
            ], 403);
        }

        if (! $this->accountDrillDownEnabledForRequest($request, $target)) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Permission Denied: account drill down is not enabled for this Class Intake.',
            ], 403);
        }

        $canViewSpyRows = $this->canViewSpyAccountDrillDownRows($viewerEmail);

        $chain = [];
        $visitedEmails = [];
        $current = $target;
        $stopReason = 'root_account';
        $maxDepth = 25;

        for ($depth = 0; $depth < $maxDepth && $current; $depth++) {
            $currentEmailKey = strtolower((string) ($current['email'] ?? ''));

            if (! $canViewSpyRows && ($current['is_spy'] ?? false) && ! ($depth === 0 && $canViewNotificationActor)) {
                $chain[] = $this->redactedSpyAccountDrillDownNode();
                $stopReason = 'hidden_spy';
                break;
            }

            if ($currentEmailKey !== '') {
                if (isset($visitedEmails[$currentEmailKey])) {
                    $stopReason = 'loop_detected';
                    break;
                }

                $visitedEmails[$currentEmailKey] = true;
            }

            $chain[] = $current;
            $createdByEmail = $current['created_by_email'] ?? null;

            if (! $createdByEmail) {
                $stopReason = 'root_account';
                break;
            }

            $next = $this->accountDrillDownNodeByEmail($createdByEmail);
            if (! $next) {
                $stopReason = 'creator_not_found';
                break;
            }

            $current = $next;
        }

        if (count($chain) >= $maxDepth && $stopReason === 'root_account') {
            $stopReason = 'max_depth_reached';
        }

        $root = count($chain) > 0 ? $chain[count($chain) - 1] : null;

        return response()->json([
            'outcome' => 'SUCCESS',
            'data' => [
                'target' => $target,
                'chain' => $chain,
                'root' => $root,
                'chain_depth' => max(0, count($chain) - 1),
                'stop_reason' => $stopReason,
            ],
        ], 200);
    }

    private function canRestoreDeletedUsers(?string $email): bool
    {
        return $this->isStaffRoleEmail($email, ['Admin', 'Protector']);
    }

    private function isGameUserAdminActor(?string $email): bool
    {
        $gameUser = $this->actorGameUser($email);

        return $gameUser && strcasecmp((string) $gameUser->game_role, 'Admin') === 0;
    }

    private function actorGameUser(?string $email): ?GameUser
    {
        if (! $email) {
            return null;
        }

        return GameUser::where('email', $email)->first();
    }

    private function canBanStaffUser(?string $actorEmail, User $targetUser): bool
    {
        if ($this->isStaffAdmin($targetUser)) {
            return false;
        }

        $actorStaff = $actorEmail ? User::where('email', $actorEmail)->first() : null;
        if ($actorStaff) {
            $roleName = $this->staffRoleName($actorStaff);

            return in_array(strtolower($roleName), ['admin', 'protector', 'trainer'], true);
        }

        $actorGameUser = $this->actorGameUser($actorEmail);
        $actorGameRole = strtolower((string) $actorGameUser?->game_role);

        return in_array($actorGameRole, ['admin', 'protector', 'spy'], true);
    }

    private function canDeleteStaffUser(?string $actorEmail, User $targetUser): bool
    {
        if ($this->isStaffAdmin($targetUser)) {
            return false;
        }

        $actorStaff = $actorEmail ? User::where('email', $actorEmail)->first() : null;
        if (! $actorStaff) {
            return false;
        }

        $roleName = $this->staffRoleName($actorStaff);

        return in_array(strtolower($roleName), ['admin', 'protector', 'trainer'], true);
    }

    private function canUnbanStaffUser(?string $actorEmail, User $targetUser): bool
    {
        if (strtoupper((string) $targetUser->status) === 'DELETED') {
            return $this->canRestoreDeletedUsers($actorEmail);
        }

        return $this->canBanStaffUser($actorEmail, $targetUser);
    }

    private function permanentDeleteAuditComment(string $baseComment, ?string $targetName, ?string $targetEmail): string
    {
        $cleanValue = fn ($value) => trim(str_replace(["\r", "\n", ';'], ' ', (string) $value));

        return trim($baseComment)
            . '; target_name=' . $cleanValue($targetName)
            . '; target_email=' . $cleanValue($targetEmail);
    }

    private function permanentDeleteAuditTarget(?string $comments): array
    {
        if (! $comments) {
            return ['target_name' => null, 'target_email' => null];
        }

        if (! preg_match('/;\s*target_name=([^;]*);\s*target_email=([^;]*)/i', $comments, $matches)) {
            return ['target_name' => null, 'target_email' => null];
        }

        return [
            'target_name' => trim($matches[1]) ?: null,
            'target_email' => trim($matches[2]) ?: null,
        ];
    }

    private function staffRoleName(User $user): string
    {
        return (string) ($user->role_name ?: optional($user->roles()->first())->name);
    }

    private function isStaffAdmin(User $user): bool
    {
        return strcasecmp($this->staffRoleName($user), 'Admin') === 0;
    }

    private function roleInputIsAdmin($roleName, $roleId = null): bool
    {
        if ($roleName && strcasecmp((string) $roleName, 'Admin') === 0) {
            return true;
        }

        if ($roleId) {
            $role = Role::where('id', $roleId)->first();

            return $role && strcasecmp((string) $role->name, 'Admin') === 0;
        }

        return false;
    }

    private function nonAdminStaffAssigningAdmin(?string $actorEmail, $roleId = null, $roleName = null): bool
    {
        if (! $this->roleInputIsAdmin($roleName, $roleId)) {
            return false;
        }

        $actorStaff = $actorEmail ? User::where('email', $actorEmail)->first() : null;

        return $actorStaff && ! $this->isStaffAdmin($actorStaff);
    }

    private function staffAssignedIntakeQuery(User $user)
    {
        $now = now();

        return DB::table('game_intakes')
            ->where(function ($query) use ($user, $now) {
                $query->whereExists(function ($assignmentQuery) use ($user, $now) {
                    $assignmentQuery->select(DB::raw(1))
                        ->from('staff_intake_assignments')
                        ->whereColumn('staff_intake_assignments.game_intake_id', 'game_intakes.id')
                        ->where('staff_intake_assignments.staff_user_id', $user->id)
                        ->where('staff_intake_assignments.active', true)
                        ->where(function ($dateQuery) use ($now) {
                            $dateQuery->whereNull('staff_intake_assignments.starts_at')
                                ->orWhere('staff_intake_assignments.starts_at', '<=', $now);
                        })
                        ->where(function ($dateQuery) use ($now) {
                            $dateQuery->whereNull('staff_intake_assignments.ends_at')
                                ->orWhere('staff_intake_assignments.ends_at', '>=', $now);
                        });
                })
                    ->orWhere('game_intakes.trainer_user_id', $user->id);
            });
    }

    private function staffVisibleIntakes(User $user)
    {
        $query = DB::table('game_intakes')
            ->select(
                'game_intakes.id',
                'game_intakes.code',
                'game_intakes.name',
                'game_intakes.status',
                'game_intakes.active_week',
                'game_intakes.trainer_user_id'
            );

        if (! $this->isStaffAdmin($user)) {
            $assignedIds = $this->staffAssignedIntakeQuery($user)->pluck('game_intakes.id');
            $query->whereIn('game_intakes.id', $assignedIds);
        }

        return $query
            ->orderByRaw("CASE WHEN game_intakes.status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('game_intakes.code')
            ->get()
            ->map(function ($intake) {
                return [
                    'id' => (int) $intake->id,
                    'code' => $intake->code,
                    'name' => $intake->name,
                    'status' => $intake->status,
                    'activeWeek' => $intake->active_week,
                    'trainer_user_id' => $intake->trainer_user_id ? (int) $intake->trainer_user_id : null,
                ];
            })
            ->values();
    }

    private function staffCanAccessIntake(User $user, ?int $intakeId, ?string $intakeCode): bool
    {
        if (! $intakeId && ! $intakeCode) {
            return false;
        }

        if ($this->isStaffAdmin($user)) {
            return true;
        }

        $query = $this->staffAssignedIntakeQuery($user);

        if ($intakeCode) {
            $query->where('game_intakes.code', $intakeCode);
        } else {
            $query->where('game_intakes.id', $intakeId);
        }

        return $query->exists();
    }

    private function historyIntakeScope(?string $viewerEmail, $requestedIntakeId, ?string $requestedIntakeCode): array
    {
        $requestedIntakeId = $requestedIntakeId ? (int) $requestedIntakeId : null;
        $requestedIntakeCode = $requestedIntakeCode ? trim($requestedIntakeCode) : null;

        $viewer = $viewerEmail ? User::where('email', $viewerEmail)->first() : null;
        if ($viewer) {
            if ($this->isStaffAdmin($viewer)) {
                if ($requestedIntakeCode) {
                    return ['mode' => 'single_code', 'code' => $requestedIntakeCode];
                }

                if ($requestedIntakeId) {
                    return ['mode' => 'single_id', 'id' => $requestedIntakeId];
                }

                return ['mode' => 'all'];
            }

            if ($requestedIntakeCode || $requestedIntakeId) {
                if ($this->staffCanAccessIntake($viewer, $requestedIntakeId, $requestedIntakeCode)) {
                    return $requestedIntakeCode
                        ? ['mode' => 'single_code', 'code' => $requestedIntakeCode]
                        : ['mode' => 'single_id', 'id' => $requestedIntakeId];
                }

                return ['mode' => 'none'];
            }

            $assignedIds = $this->staffAssignedIntakeQuery($viewer)
                ->pluck('game_intakes.id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            return count($assignedIds) > 0
                ? ['mode' => 'multiple_ids', 'ids' => $assignedIds]
                : ['mode' => 'none'];
        }

        $viewerGameUser = $viewerEmail ? GameUser::where('email', $viewerEmail)->first() : null;
        if ($viewerGameUser) {
            $intake = DB::table('game_intakes')->where('id', $viewerGameUser->intake_id)->first();

            return $intake
                ? ['mode' => 'single_code', 'code' => $intake->code]
                : ['mode' => 'single_id', 'id' => (int) $viewerGameUser->intake_id];
        }

        return ['mode' => 'none'];
    }

    private function applyHistoryIntakeScope($query, array $scope)
    {
        if (($scope['mode'] ?? 'none') === 'all') {
            return $query;
        }

        return $query->where(function ($scopeQuery) use ($scope) {
            $scopeQuery->whereNull('game_users.id');

            $scopeQuery->orWhere(function ($studentQuery) use ($scope) {
                switch ($scope['mode'] ?? 'none') {
                    case 'single_code':
                        $studentQuery->where('game_intakes.code', $scope['code']);
                        break;
                    case 'single_id':
                        $studentQuery->where('game_users.intake_id', $scope['id']);
                        break;
                    case 'multiple_ids':
                        $studentQuery->whereIn('game_users.intake_id', $scope['ids']);
                        break;
                    default:
                        $studentQuery->whereRaw('1 = 0');
                        break;
                }
            });
        });
    }

    public function F0_VMD_get_staff_game_intakes(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'vmd_user_email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Validation failed.',
                'errors' => $validator->errors()->toArray(),
            ], 409);
        }

        $user = User::where('email', $request->input('vmd_user_email'))->first();
        if (! $user) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Staff user not found.',
                'data' => [],
            ], 404);
        }

        $roleName = $this->staffRoleName($user);
        $intakes = $this->staffVisibleIntakes($user);

        return response()->json([
            'outcome' => 'SUCCESS',
            'role_name' => $roleName,
            'is_staff_admin' => strcasecmp($roleName, 'Admin') === 0,
            'data' => $intakes,
        ], 200);
    }

    private function staffAdminFromRequest(Request $request): ?User
    {
        $email = $request->input('vmd_user_email') ?: $request->query('vmd_user_email');
        if (! $email) {
            return null;
        }

        $user = User::where('email', $email)->first();
        if (! $user || ! $this->isStaffAdmin($user)) {
            return null;
        }

        return $user;
    }

    private function staffIntakeAssignmentsMissingResponse()
    {
        return response()->json([
            'outcome' => 'FAIL',
            'message' => 'Staff intake assignments table is missing. Run migrations.',
        ], 500);
    }

    private function isMissingStaffIntakeAssignmentsException(QueryException $exception): bool
    {
        return str_contains($exception->getMessage(), 'staff_intake_assignments')
            && str_contains($exception->getMessage(), 'Base table or view not found');
    }

    private function classIntakeManagementPayload(): array
    {
        $intakes = DB::table('game_intakes')
            ->leftJoin('game_users', 'game_users.intake_id', '=', 'game_intakes.id')
            ->select(
                'game_intakes.id',
                'game_intakes.code',
                'game_intakes.name',
                'game_intakes.status',
                'game_intakes.active_week',
                'game_intakes.trainer_user_id',
                DB::raw('COUNT(game_users.id) as students')
            )
            ->groupBy(
                'game_intakes.id',
                'game_intakes.code',
                'game_intakes.name',
                'game_intakes.status',
                'game_intakes.active_week',
                'game_intakes.trainer_user_id'
            )
            ->orderByRaw("CASE WHEN game_intakes.status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('game_intakes.code')
            ->get()
            ->map(function ($intake) {
                return [
                    'id' => (int) $intake->id,
                    'code' => $intake->code,
                    'name' => $intake->name,
                    'course' => $intake->name,
                    'status' => $intake->status,
                    'activeWeek' => $intake->active_week,
                    'trainer_user_id' => $intake->trainer_user_id ? (int) $intake->trainer_user_id : null,
                    'students' => (int) $intake->students,
                ];
            })
            ->values();

        $assignments = DB::table('staff_intake_assignments')
            ->join('game_intakes', 'game_intakes.id', '=', 'staff_intake_assignments.game_intake_id')
            ->join('users', 'users.id', '=', 'staff_intake_assignments.staff_user_id')
            ->where('staff_intake_assignments.active', true)
            ->select(
                'game_intakes.code as game_intake_code',
                'staff_intake_assignments.assignment_type',
                'users.id',
                'users.name',
                'users.email',
                'users.role_name',
                'users.profile_image'
            )
            ->orderBy('users.name')
            ->get()
            ->groupBy('game_intake_code')
            ->map(function ($rows) {
                return $rows->map(function ($row) {
                    return [
                        'id' => (int) $row->id,
                        'name' => $row->name,
                        'email' => $row->email,
                        'role_name' => $row->role_name,
                        'profile_image' => $row->profile_image,
                        'assignment_type' => $row->assignment_type,
                    ];
                })->values();
            });

        $staff = User::query()
            ->select('id', 'name', 'email', 'role_name', 'profile_image', 'status')
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereNotIn('status', ['BANNED', 'DELETED']);
            })
            ->orderBy('name')
            ->get()
            ->map(function ($user) {
                return [
                    'id' => (int) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role_name' => $user->role_name,
                    'profile_image' => $user->profile_image,
                    'status' => $user->status,
                ];
            })
            ->values();

        return [
            'intakes' => $intakes,
            'assignments' => $assignments,
            'staff' => $staff,
        ];
    }

    private function classIntakeRosterPayload(string $gameIntakeCode): ?array
    {
        $intake = DB::table('game_intakes')
            ->where('code', $gameIntakeCode)
            ->first();

        if (! $intake) {
            return null;
        }

        $roster = DB::table('game_users')
            ->join('game_intakes', 'game_intakes.id', '=', 'game_users.intake_id')
            ->where('game_intakes.code', $gameIntakeCode)
            ->select(
                'game_users.id',
                'game_intakes.code as intake_code',
                'game_users.display_name',
                'game_users.email',
                'game_users.game_role',
                'game_users.game_status',
                'game_users.created_at'
            )
            ->orderBy('game_users.display_name')
            ->get()
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'game_intake_code' => $row->intake_code,
                    'displayName' => $row->display_name,
                    'email' => $row->email,
                    'gameRole' => $row->game_role,
                    'gameStatus' => $row->game_status,
                    'created_at' => $row->created_at,
                ];
            })
            ->values();

        return [
            'intake' => [
                'code' => $intake->code,
                'name' => $intake->name,
                'status' => $intake->status,
                'activeWeek' => $intake->active_week,
            ],
            'roster' => $roster,
        ];
    }

    private function createStaffIntakeAssignmentNotifications(
        $staffUserIds,
        object $intake,
        User $adminUser,
        string $changeType
    ): void {
        $ids = collect($staffUserIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $staffUsers = User::query()
            ->whereIn('id', $ids->all())
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereNotIn('status', ['BANNED', 'DELETED']);
            })
            ->get();

        foreach ($staffUsers as $staffUser) {
            $granted = $changeType === 'granted';

            $this->createPersistedNotification([
                'recipient_email' => $staffUser->email,
                'actor_email' => $adminUser->email,
                'type' => $granted ? 'info' : 'warning',
                'title' => $granted ? 'Class Intake access granted' : 'Class Intake access removed',
                'message' => $granted
                    ? "You have been linked to {$intake->name} ({$intake->code})."
                    : "Your access to {$intake->name} ({$intake->code}) has been removed.",
                'source' => 'class-intake-management',
                'metadata' => [
                    'change_type' => $changeType,
                    'game_intake_id' => (int) $intake->id,
                    'game_intake_code' => $intake->code,
                    'game_intake_name' => $intake->name,
                    'assigned_by_user_id' => (int) $adminUser->id,
                    'assigned_by_email' => $adminUser->email,
                ],
            ]);
        }
    }

    public function F0_VMD_get_class_intake_management_data(Request $request)
    {
        $adminUser = $this->staffAdminFromRequest($request);
        if (! $adminUser) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Permission Denied: only Staff users with Admin powers can manage Class Intake assignments.',
            ], 403);
        }

        if (! Schema::hasTable('staff_intake_assignments')) {
            return $this->staffIntakeAssignmentsMissingResponse();
        }

        try {
            return response()->json([
                'outcome' => 'SUCCESS',
                'data' => $this->classIntakeManagementPayload(),
            ], 200);
        } catch (QueryException $exception) {
            if ($this->isMissingStaffIntakeAssignmentsException($exception)) {
                return $this->staffIntakeAssignmentsMissingResponse();
            }

            throw $exception;
        }
    }

    public function F0_VMD_get_class_intake_roster(Request $request)
    {
        $adminUser = $this->staffAdminFromRequest($request);
        if (! $adminUser) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Permission Denied: only Staff users with Admin powers can view Class Intake rosters.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'game_intake_code' => 'required|string|exists:game_intakes,code',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Validation failed.',
                'errors' => $validator->errors()->toArray(),
            ], 409);
        }

        $payload = $this->classIntakeRosterPayload($request->input('game_intake_code'));

        if (! $payload) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Class Intake not found.',
            ], 404);
        }

        return response()->json([
            'outcome' => 'SUCCESS',
            'data' => $payload,
        ], 200);
    }

    public function F0_VMD_add_class_intake_student(Request $request)
    {
        $adminUser = $this->staffAdminFromRequest($request);
        if (! $adminUser) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Permission Denied: only Staff users with Admin powers can add Students to a Class Intake.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'game_intake_code' => 'required|string|exists:game_intakes,code',
            'first_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:6|max:255',
            'company_name' => 'nullable|string|max:255',
            'gender' => 'nullable|string|max:50',
            'location' => 'nullable|string|max:255',
            'phone_no' => 'nullable|string|max:50',
            'languages' => 'nullable|array',
            'languages.*' => 'string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Validation failed.',
                'errors' => $validator->errors()->toArray(),
            ], 409);
        }

        $validated = $validator->validated();
        $gameIntake = DB::table('game_intakes')->where('code', $validated['game_intake_code'])->first();

        $existingStaffUser = DB::table('users')
            ->where('email', $validated['email'])
            ->exists();

        if ($existingStaffUser) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'A Staff user with this email already exists.',
            ], 409);
        }

        $existingStudent = DB::table('game_users')
            ->join('game_intakes', 'game_intakes.id', '=', 'game_users.intake_id')
            ->where('game_intakes.code', $validated['game_intake_code'])
            ->where('game_users.email', $validated['email'])
            ->exists();

        if ($existingStudent) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'A Student with this email already exists in the selected Class Intake.',
            ], 409);
        }

        $displayName = trim($validated['first_name']);

        DB::transaction(function () use ($gameIntake, $validated, $displayName, $adminUser) {
            DB::table('game_users')->insert([
                'intake_id' => $gameIntake->id,
                'first_name' => $validated['first_name'],
                'surname' => null,
                'preferred_name' => null,
                'display_name' => $displayName,
                'email' => $validated['email'],
                'created_by_email' => $adminUser->email,
                'password' => Hash::make($validated['password']),
                'must_change_password' => true,
                'company_name' => $validated['company_name'] ?? $gameIntake->name,
                'gender' => $validated['gender'] ?? null,
                'location' => $validated['location'] ?? null,
                'phone_no' => $validated['phone_no'] ?? null,
                'languages' => json_encode($validated['languages'] ?? []),
                'game_role' => 'Creator',
                'game_status' => 'active',
                'is_spy' => false,
                'is_protector' => false,
                'metadata' => json_encode([
                    'source' => 'class_intake_management_add_student',
                    'created_by_email' => $adminUser->email,
                    'game_intake_code' => $gameIntake->code,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json([
            'outcome' => 'SUCCESS',
            'message' => "{$displayName} has been added to {$gameIntake->code}.",
            'data' => $this->classIntakeRosterPayload($validated['game_intake_code']),
        ], 201);
    }

    public function F0_VMD_save_staff_intake_assignments(Request $request)
    {
        $adminUser = $this->staffAdminFromRequest($request);
        if (! $adminUser) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Permission Denied: only Staff users with Admin powers can manage Class Intake assignments.',
            ], 403);
        }

        if (! Schema::hasTable('staff_intake_assignments')) {
            return $this->staffIntakeAssignmentsMissingResponse();
        }

        $validator = Validator::make($request->all(), [
            'game_intake_code' => 'required|string|exists:game_intakes,code',
            'staff_user_ids' => 'array',
            'staff_user_ids.*' => 'integer|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Validation failed.',
                'errors' => $validator->errors()->toArray(),
            ], 409);
        }

        $gameIntake = DB::table('game_intakes')->where('code', $request->input('game_intake_code'))->first();
        $gameIntakeId = (int) $gameIntake->id;
        $staffUserIds = collect($request->input('staff_user_ids', []))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($staffUserIds->isNotEmpty()) {
            $availableStaffUserIds = User::query()
                ->whereIn('id', $staffUserIds->all())
                ->where(function ($query) {
                    $query->whereNull('status')
                        ->orWhereNotIn('status', ['BANNED', 'DELETED']);
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values();

            if ($availableStaffUserIds->count() !== $staffUserIds->count()) {
                return response()->json([
                    'outcome' => 'FAIL',
                    'message' => 'One or more selected Staff users are no longer active.',
                ], 409);
            }
        }

        try {
            DB::transaction(function () use ($gameIntakeId, $staffUserIds, $adminUser, $gameIntake) {
                $previousStaffUserIds = DB::table('staff_intake_assignments')
                    ->where('game_intake_id', $gameIntakeId)
                    ->where('assignment_type', 'trainer')
                    ->where('active', true)
                    ->pluck('staff_user_id')
                    ->map(fn ($id) => (int) $id)
                    ->values();
                $addedStaffUserIds = $staffUserIds->diff($previousStaffUserIds)->values();
                $removedStaffUserIds = $previousStaffUserIds->diff($staffUserIds)->values();

                $deactivateQuery = DB::table('staff_intake_assignments')
                    ->where('game_intake_id', $gameIntakeId)
                    ->where('assignment_type', 'trainer');

                if ($staffUserIds->isNotEmpty()) {
                    $deactivateQuery->whereNotIn('staff_user_id', $staffUserIds->all());
                }

                $deactivateQuery->update([
                    'active' => false,
                    'ends_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($staffUserIds as $staffUserId) {
                    DB::table('staff_intake_assignments')->updateOrInsert(
                        [
                            'staff_user_id' => $staffUserId,
                            'game_intake_id' => $gameIntakeId,
                            'assignment_type' => 'trainer',
                        ],
                        [
                            'active' => true,
                            'starts_at' => null,
                            'ends_at' => null,
                            'assigned_by_user_id' => $adminUser->id,
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );
                }

                $this->createStaffIntakeAssignmentNotifications(
                    $addedStaffUserIds,
                    $gameIntake,
                    $adminUser,
                    'granted'
                );
                $this->createStaffIntakeAssignmentNotifications(
                    $removedStaffUserIds,
                    $gameIntake,
                    $adminUser,
                    'removed'
                );
            });

            return response()->json([
                'outcome' => 'SUCCESS',
                'message' => 'Staff intake assignments saved.',
                'data' => $this->classIntakeManagementPayload(),
            ], 200);
        } catch (QueryException $exception) {
            if ($this->isMissingStaffIntakeAssignmentsException($exception)) {
                return $this->staffIntakeAssignmentsMissingResponse();
            }

            throw $exception;
        }
    }

    private function documentationViewerIdentity(?string $email): ?string
    {
        if (! $email) {
            return null;
        }

        $user = User::where('email', $email)->first();
        if ($user) {
            return 'staff';
        }

        $gameUser = GameUser::where('email', $email)->first();
        return $gameUser ? 'student' : null;
    }

    public function F0_VMD_get_documentation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'audience' => 'required|string|in:student,staff',
            'slug' => 'required|string|regex:/^[a-z0-9-]+$/',
            'vmd_user_email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Validation failed.',
                'errors' => $validator->errors()->toArray(),
            ], 409);
        }

        $data = $validator->validated();
        $viewerIdentity = $this->documentationViewerIdentity($data['vmd_user_email']);

        if (! $viewerIdentity) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Documentation access denied.',
            ], 403);
        }

        if ($data['audience'] === 'staff' && $viewerIdentity !== 'staff') {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Documentation access denied.',
            ], 403);
        }

        $path = storage_path("app/private/docs/{$data['audience']}/{$data['slug']}.md");

        if (! File::exists($path)) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Documentation file not found.',
            ], 404);
        }

        return response()->json([
            'outcome' => 'SUCCESS',
            'audience' => $data['audience'],
            'slug' => $data['slug'],
            'content' => File::get($path),
        ], 200);
    }

    private function twoFactorRecipientsForEmail(?string $accountEmail): array
    {
        if (!$this->dashboardSettingBoolean('login_2fa_enabled', true)) {
            return [];
        }

        $recipients = [];

        if ($accountEmail && $this->dashboardSettingBoolean('login_2fa_send_to_account', true)) {
            $recipients[] = $accountEmail;
        }

        if ($this->dashboardSettingBoolean('login_2fa_send_to_master', true)) {
            $masterEmail = $this->dashboardSettingValue('login_2fa_master_email', 'ivanvetsich@gmail.com');

            if ($masterEmail) {
                $recipients[] = $masterEmail;
            }
        }

        return array_values(array_unique(array_filter($recipients)));
    }

    private function twoFactorRecipients(User $user): array
    {
        return $this->twoFactorRecipientsForEmail($user->email);
    }

    private function twoFactorCacheKey(string $identityType, int $id): string
    {
        $identity = strtolower($identityType) === 'student' ? 'student' : 'staff';

        return "2fa_code_{$identity}_{$id}";
    }

    private function sendTwoFactorCodeForIdentity(string $identityType, int $id, string $email): int
    {
        $authenticationCode = random_int(100000, 999999);
        cache()->put($this->twoFactorCacheKey($identityType, $id), $authenticationCode, now()->addMinutes(10));

        $recipients = $this->twoFactorRecipientsForEmail($email);

        if (count($recipients) === 0) {
            throw new \RuntimeException('2FA is enabled but no 2FA email recipients are configured.');
        }

        foreach ($recipients as $recipient) {
            Mail::to($recipient)->send(new TwoFactorCodeMail($authenticationCode, [
                'recipient_email' => $email,
                'identity_type' => strtolower($identityType) === 'student' ? 'student' : 'staff',
            ]));
        }

        return $authenticationCode;
    }

    private function getRequestIpAddress(Request $request): string
    {
        $clientReportedIpV4 = $this->normalizeIpAddress($request->input('vmd_ip_address_v4'));
        if ($clientReportedIpV4 && $clientReportedIpV4['ip_address_v4']) {
            return $clientReportedIpV4['ip_address_v4'];
        }

        $clientReportedIpV6 = $this->normalizeIpAddress($request->input('vmd_ip_address_v6'));
        if ($clientReportedIpV6 && $clientReportedIpV6['ip_address_v6']) {
            return $clientReportedIpV6['ip_address_v6'];
        }

        $resolved = $this->resolveClientIpAddresses($request);
        $realIp = $resolved['ip_address_v4'] ?: $resolved['ip_address'] ?: $request->ip();

        return trim($realIp ?: 'Unknown');
    }

    private function logProtectedAccountEditBlocked(Request $request, array $data, string $attemptedAction): void
    {
        $targetId = (int) ($data['id'] ?? 1);
        $targetUser = User::where('id', $targetId)->first();
        $actorEmail = $data['vmd_user_email'] ?? $data['updated_by'] ?? 'Unknown email';
        $actorName = $data['vmd_user_name'] ?? $data['updated_by'] ?? 'Unknown actor';
        $reason = $data['vmd_audit_reason'] ?? $attemptedAction;
        $targetEmail = $targetUser->email ?? 'unknown target';
        $targetName = $targetUser->name ?? 'protected account';
        $targetRole = $targetUser->role_name ?? 'Unknown';
        $attemptedRole = $data['role_name'] ?? null;
        $comments = "Protected account warning; {$actorEmail} attempted {$reason} on {$targetName} ({$targetEmail}).";

        if ($attemptedRole && $attemptedRole !== $targetRole) {
            $comments = "Protected account warning; {$actorEmail} attempted to change the User role for {$targetName} ({$targetEmail}) from {$targetRole} to {$attemptedRole}.";
        }

        $auditHistoryId = DB::table('user_audit_history')->insertGetId([
            'custno' => $targetId + 100000,
            'dteprfmd' => now(),
            'comments' => $comments,
            'clerk_id' => $actorName,
            'created_by_email' => $actorEmail,
            'created_by_ip_address' => $this->getRequestIpAddress($request),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createProtectedAccountWarningNotifications($auditHistoryId, [
            'actor_email' => $actorEmail,
            'actor_name' => $actorName,
            'target_email' => $targetEmail,
            'target_name' => $targetName,
            'message' => $comments,
            'ip_address' => $this->getRequestIpAddress($request),
        ]);
    }

    private function normalizeNotificationMetadata($metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function createPersistedNotification(array $notification): ?object
    {
        $recipientEmail = $notification['recipient_email'] ?? null;
        if (! $recipientEmail) {
            return null;
        }

        $recipient = DB::table('users')->where('email', $recipientEmail)->first();
        $actorEmail = $notification['actor_email'] ?? null;
        $actor = $actorEmail ? DB::table('users')->where('email', $actorEmail)->first() : null;
        $dedupeKey = $notification['dedupe_key'] ?? null;
        $now = now();

        if ($dedupeKey) {
            $existing = DB::table('user_notifications')
                ->where('recipient_email', $recipientEmail)
                ->where('dedupe_key', $dedupeKey)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $notificationId = DB::table('user_notifications')->insertGetId([
            'recipient_user_id' => $recipient->id ?? null,
            'recipient_email' => $recipientEmail,
            'actor_user_id' => $actor->id ?? null,
            'actor_email' => $actorEmail,
            'type' => $notification['type'] ?? 'info',
            'title' => $notification['title'] ?? 'Notification',
            'message' => $notification['message'] ?? null,
            'source' => $notification['source'] ?? 'system',
            'related_audit_history_id' => $notification['related_audit_history_id'] ?? null,
            'dedupe_key' => $dedupeKey,
            'metadata' => json_encode($notification['metadata'] ?? []),
            'read_at' => $notification['read_at'] ?? null,
            'created_at' => $notification['created_at'] ?? $now,
            'updated_at' => $notification['updated_at'] ?? $now,
        ]);

        return DB::table('user_notifications')->where('id', $notificationId)->first();
    }

    private function auditNotificationDetails(?string $comments): array
    {
        $commentText = trim((string) $comments);
        $lowerComment = strtolower($commentText);

        if (str_contains($lowerComment, 'protected account')) {
            return [
                'type' => 'warning',
                'title' => 'Security notice',
                'message' => $commentText ?: 'A protected account security event was recorded.',
            ];
        }

        if (str_contains($lowerComment, 'password')) {
            return [
                'type' => 'warning',
                'title' => 'Password changed',
                'message' => $commentText ?: 'Your password was changed.',
            ];
        }

        if (str_contains($lowerComment, 'role')) {
            return [
                'type' => 'warning',
                'title' => 'Role changed',
                'message' => $commentText ?: 'Your role or permissions were changed.',
            ];
        }

        if (str_contains($lowerComment, 'unbanned') || str_contains($lowerComment, 'restored')) {
            return [
                'type' => 'success',
                'title' => 'Account restored',
                'message' => $commentText ?: 'Your account access was restored.',
            ];
        }

        if (str_contains($lowerComment, 'banned') || str_contains($lowerComment, 'deleted')) {
            return [
                'type' => 'error',
                'title' => 'Account access changed',
                'message' => $commentText ?: 'Your account access was changed.',
            ];
        }

        if (
            str_contains($lowerComment, 'profile') ||
            str_contains($lowerComment, 'basic info') ||
            str_contains($lowerComment, 'avatar')
        ) {
            return [
                'type' => 'info',
                'title' => 'Profile updated',
                'message' => $commentText ?: 'Your profile was updated.',
            ];
        }

        return [
            'type' => 'info',
            'title' => 'Account activity',
            'message' => $commentText ?: 'Account activity was recorded.',
        ];
    }

    private function createAuditBackfillNotificationsForEmail(string $email): void
    {
        if (! Schema::hasTable('user_notifications') || ! Schema::hasTable('user_audit_history')) {
            return;
        }

        $normalizedEmail = strtolower(trim($email));
        if ($normalizedEmail === '') {
            return;
        }

        $auditRows = DB::table('user_audit_history')
            ->leftJoin('users', 'users.id', '=', DB::raw('user_audit_history.custno - 100000'))
            ->leftJoin('game_users', 'game_users.id', '=', DB::raw('user_audit_history.custno - 900000'))
            ->leftJoin('game_intakes', 'game_users.intake_id', '=', 'game_intakes.id')
            ->leftJoin('users as actor_users', 'actor_users.email', '=', 'user_audit_history.created_by_email')
            ->leftJoin('game_users as actor_game_users', 'actor_game_users.email', '=', 'user_audit_history.created_by_email')
            ->select(
                'user_audit_history.*',
                DB::raw("COALESCE(users.name, game_users.display_name, TRIM(CONCAT(COALESCE(game_users.preferred_name, game_users.first_name, ''), ' ', COALESCE(game_users.surname, '')))) as target_name"),
                DB::raw('COALESCE(users.email, game_users.email) as target_email'),
                DB::raw('COALESCE(users.role_name, game_users.game_role) as target_role_name'),
                DB::raw('COALESCE(actor_users.name, actor_game_users.display_name) as actor_name'),
                DB::raw('COALESCE(actor_users.role_name, actor_game_users.game_role) as actor_role_name'),
                DB::raw("CASE WHEN actor_users.id IS NOT NULL THEN 'staff' WHEN actor_game_users.id IS NOT NULL THEN 'student' ELSE NULL END as actor_identity_type"),
                DB::raw('actor_game_intakes.code as actor_intake_code'),
                DB::raw('game_intakes.code as intake_code')
            )
            ->leftJoin('game_intakes as actor_game_intakes', 'actor_game_users.intake_id', '=', 'actor_game_intakes.id')
            ->whereRaw('LOWER(COALESCE(users.email, game_users.email)) = ?', [$normalizedEmail])
            ->orderBy('user_audit_history.created_at', 'DESC')
            ->limit(100)
            ->get();

        foreach ($auditRows as $auditRow) {
            $details = $this->auditNotificationDetails($auditRow->comments);
            $targetEmail = $auditRow->target_email ?: $email;

            if ($this->hasDirectStatusNotificationNearAuditRow($targetEmail, $auditRow, $details)) {
                continue;
            }

            $metadata = [
                'audit_history_id' => intval($auditRow->id),
                'targetEmail' => $targetEmail,
                'targetName' => $auditRow->target_name,
                'targetRole' => $auditRow->target_role_name,
                'actorName' => $auditRow->actor_name ?: $auditRow->clerk_id,
                'actorEmail' => $auditRow->created_by_email,
                'actorRole' => $auditRow->actor_role_name,
                'actorIdentityType' => $auditRow->actor_identity_type,
                'actorIntakeCode' => $auditRow->actor_intake_code,
                'ipAddress' => $auditRow->created_by_ip_address,
                'auditReason' => $auditRow->comments,
            ];

            if ($auditRow->custno >= 900000) {
                $metadata['identity_type'] = 'student';
                $metadata['game_user_id'] = intval($auditRow->custno) - 900000;
                $metadata['intake_code'] = $auditRow->intake_code;
            } else {
                $metadata['identity_type'] = 'staff';
                $metadata['user_id'] = intval($auditRow->custno) - 100000;
            }

            $this->createPersistedNotification([
                'recipient_email' => $targetEmail,
                'actor_email' => $auditRow->created_by_email,
                'type' => $details['type'],
                'title' => $details['title'],
                'message' => $details['message'],
                'source' => 'audit-history',
                'related_audit_history_id' => intval($auditRow->id),
                'dedupe_key' => 'audit-history-' . intval($auditRow->id) . '-' . $normalizedEmail,
                'metadata' => $metadata,
                'created_at' => $auditRow->created_at ?: now(),
                'updated_at' => $auditRow->updated_at ?: ($auditRow->created_at ?: now()),
            ]);
        }
    }

    private function hasDirectStatusNotificationNearAuditRow(string $recipientEmail, object $auditRow, array $details): bool
    {
        $statusTitles = [
            'Account deleted',
            'Account undeleted',
            'Account unbanned',
            'You have been unbanned',
        ];

        $auditText = strtolower((string) ($auditRow->comments ?? '') . ' ' . ($details['title'] ?? ''));
        $isStatusAudit =
            str_contains($auditText, 'deleted') ||
            str_contains($auditText, 'undeleted') ||
            str_contains($auditText, 'banned') ||
            str_contains($auditText, 'unbanned') ||
            str_contains($auditText, 'restored');

        if (! $isStatusAudit) {
            return false;
        }

        $auditTime = $auditRow->created_at ?: now();

        return DB::table('user_notifications')
            ->where('recipient_email', $recipientEmail)
            ->where('source', 'user-management')
            ->whereIn('title', $statusTitles)
            ->whereBetween('created_at', [
                Carbon::parse($auditTime)->subMinutes(2),
                Carbon::parse($auditTime)->addMinutes(2),
            ])
            ->exists();
    }

    private function isProfileWatcherStudentUnbanArtifact(array $notification): bool
    {
        if (($notification['source'] ?? '') !== 'profile-watcher') {
            return false;
        }

        $recipientEmail = $notification['recipient_email'] ?? '';
        if (! $recipientEmail) {
            return false;
        }

        $title = (string) ($notification['title'] ?? '');
        $message = (string) ($notification['message'] ?? '');
        $combined = strtolower($title . ' ' . $message);
        $looksLikeUnbanArtifact =
            str_contains($combined, 'student was successfully unbanned') ||
            str_contains($combined, 'profile was edited') ||
            str_contains($combined, 'profile was modified') ||
            str_contains($combined, 'role was changed');

        if (! $looksLikeUnbanArtifact) {
            return false;
        }

        $gameUser = DB::table('game_users')->where('email', $recipientEmail)->first();
        if (! $gameUser) {
            return false;
        }

        return DB::table('user_audit_history')
            ->where('custno', 900000 + intval($gameUser->id))
            ->where('comments', 'like', '%UNBANNED%')
            ->where('created_at', '>=', now()->subMinutes(10))
            ->exists();
    }

    private function isRedundantProfileWatcherUserChangeNotification(array $notification): bool
    {
        if (($notification['source'] ?? '') !== 'profile-watcher') {
            return false;
        }

        $combinedText = strtolower(
            (string) ($notification['title'] ?? '') . ' ' .
            (string) ($notification['message'] ?? '') . ' ' .
            (string) (($notification['metadata']['auditReason'] ?? '') ?: '')
        );

        return str_contains($combinedText, 'role') ||
            str_contains($combinedText, 'basic info') ||
            str_contains($combinedText, 'profile');
    }

    private function createProtectedAccountWarningNotifications(int $auditHistoryId, array $event): void
    {
        $recipients = DB::table('users')
            ->where(function ($query) {
                $query->whereIn(DB::raw('LOWER(role_name)'), ['admin', 'protector', 'trainer'])
                    ->orWhereIn('role_id', [1, 5]);
            })
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereNotIn('status', ['BANNED', 'DELETED']);
            })
            ->get();

        foreach ($recipients as $recipient) {
            $this->createPersistedNotification([
                'recipient_email' => $recipient->email,
                'actor_email' => $event['actor_email'] ?? null,
                'type' => 'warning',
                'title' => 'Protected account warning',
                'message' => $event['message'] ?? '',
                'source' => 'security',
                'related_audit_history_id' => $auditHistoryId,
                'dedupe_key' => "security-protected-account-warning-{$auditHistoryId}",
                'metadata' => [
                    'actorEmail' => $event['actor_email'] ?? null,
                    'updatedBy' => $event['actor_email'] ?? null,
                    'targetEmail' => $event['target_email'] ?? null,
                    'targetName' => $event['target_name'] ?? null,
                    'auditReason' => $event['message'] ?? null,
                    'ipAddress' => $event['ip_address'] ?? null,
                ],
            ]);
        }
    }

    private function transformNotificationRow($row): array
    {
        $actorUser = $row->actor_email
            ? DB::table('users')->where('email', $row->actor_email)->first()
            : null;
        $actorGameUser = (! $actorUser && $row->actor_email)
            ? DB::table('game_users')
                ->leftJoin('game_intakes', 'game_users.intake_id', '=', 'game_intakes.id')
                ->where('game_users.email', $row->actor_email)
                ->select(
                    'game_users.*',
                    'game_intakes.code as intake_code'
                )
                ->first()
            : null;
        $metadata = $this->normalizeNotificationMetadata($row->metadata);

        if ($row->actor_email && empty($metadata['actorEmail'])) {
            $metadata['actorEmail'] = $row->actor_email;
        }

        if (($actorUser || $actorGameUser) && empty($metadata['actorName'])) {
            $metadata['actorName'] = $actorUser->name
                ?? $actorGameUser->display_name
                ?? trim(($actorGameUser->preferred_name ?? $actorGameUser->first_name ?? '') . ' ' . ($actorGameUser->surname ?? ''))
                ?: null;
        }

        if (($actorUser || $actorGameUser) && empty($metadata['actorImage'])) {
            $metadata['actorImage'] = $actorUser->profile_image ?? $actorGameUser->profile_image ?? '';
        }

        if (($actorUser || $actorGameUser) && empty($metadata['actorRole'])) {
            $metadata['actorRole'] = $actorUser->role_name ?? $actorGameUser->game_role ?? '';
        }

        if (($actorUser || $actorGameUser) && empty($metadata['actorIdentityType'])) {
            $metadata['actorIdentityType'] = $actorUser ? 'staff' : 'student';
        }

        if ($actorGameUser && empty($metadata['actorIntakeCode'])) {
            $metadata['actorIntakeCode'] = $actorGameUser->intake_code ?? '';
        }

        if (($actorUser || $actorGameUser) && empty($metadata['updatedBy'])) {
            $metadata['updatedBy'] = $actorUser->email ?? $actorGameUser->email;
        }

        return [
            'id' => (string) $row->id,
            'type' => $row->type,
            'title' => $row->title,
            'message' => $row->message ?? '',
            'source' => $row->source,
            'metadata' => $metadata,
            'read' => $row->read_at !== null,
            'createdAt' => $row->created_at,
            'dedupeKey' => $row->dedupe_key,
            'relatedAuditHistoryId' => $row->related_audit_history_id,
        ];
    }

    private function normalizeIpAddress(?string $ip): ?array
    {
        if (! $ip) {
            return null;
        }

        $ip = trim($ip);
        $ip = trim($ip, "[] \t\n\r\0\x0B");

        if ($ip === '::1' || $ip === '0:0:0:0:0:0:0:1') {
            return [
                'ip_address' => '127.0.0.1',
                'ip_address_v4' => '127.0.0.1',
                'ip_address_v6' => null,
            ];
        }

        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $matches)) {
            $ip = $matches[1];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [
                'ip_address' => $ip,
                'ip_address_v4' => $ip,
                'ip_address_v6' => null,
            ];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return [
                'ip_address' => $ip,
                'ip_address_v4' => null,
                'ip_address_v6' => $ip,
            ];
        }

        return null;
    }

    private function resolveClientIpAddresses(Request $request): array
    {
        $candidates = [];

        foreach (['CF-Connecting-IP', 'X-Real-IP', 'X-Forwarded-For'] as $headerName) {
            $headerValue = $request->header($headerName);
            if (! $headerValue) {
                continue;
            }

            foreach (explode(',', $headerValue) as $headerIp) {
                $candidates[] = $headerIp;
            }
        }

        $candidates[] = $request->ip();

        $resolved = [
            'ip_address' => '0.0.0.0',
            'ip_address_v4' => null,
            'ip_address_v6' => null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeIpAddress($candidate);
            if (! $normalized) {
                continue;
            }

            if ($resolved['ip_address'] === '0.0.0.0') {
                $resolved['ip_address'] = $normalized['ip_address'];
            }

            if (! $resolved['ip_address_v4'] && $normalized['ip_address_v4']) {
                $resolved['ip_address_v4'] = $normalized['ip_address_v4'];
            }

            if (! $resolved['ip_address_v6'] && $normalized['ip_address_v6']) {
                $resolved['ip_address_v6'] = $normalized['ip_address_v6'];
            }
        }

        if ($resolved['ip_address_v4']) {
            $resolved['ip_address'] = $resolved['ip_address_v4'];
        }

        return $resolved;
    }
// Your custom method
    public function yourCustomMethod(Request $request)
    {
        // Call the custom method (as a method, not inside the main method) 
        if ($request->isMethod('post')) {

            $method = $request->input('method');

            switch ($method) {
                case "Google OAuth":
                    return $this->F0_PFS_get_user_data($request);

                case "via User ID":
                    return $this->F0_PFS_get_user_data($request);

                case "VMD-login-user":
                    return $this->F0_VMD_login_user($request);

                case "VMD-ban-user":
                    return $this->F0_VMD_ban_user($request);

                case "VMD-updateUser":
                    return $this->F0_VMD_updateUser($request);

                default:
                    $array = [
                        'outcome' => 'FAIL: ',
                        'method' => "Invalid Calling Method",
                        'current_time' => $currentDate,
                    ];
                    // Return a JSON response with headers
                    return response()->json($array, 200)->header('Access-Control-Allow-Origin', '*');
            }
        }
    }



















    public function F0_PFS_get_user_data(Request $request)
    {

        $currentDate = now()->toDateTimeString();

        // Extract variables safely
        $id = $request->input('id', null);
        $email = $request->input('email', null);
        $method = $request->input('method', null);

        // Validate input
        $validator = Validator::make($request->all(), [
            'id' => 'nullable|integer',
            'email' => 'nullable|email',
            'method' => 'nullable|string',
            'identity_type' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        // Set a default email if needed
        if ($method === 'Google OAuth' && is_null($email)) {
            $email = 'member@jsonapi.com';
        }
        if ($method === 'via User ID' && is_null($id)) {
            $email = 'member@jsonapi.com';
        }

        if (($method === 'via Game User ID' || $request->input('identity_type') === 'student') && $id) {
            $gameUser = DB::table('game_users')
                ->leftJoin('game_intakes', 'game_users.intake_id', '=', 'game_intakes.id')
                ->where('game_users.id', $id)
                ->select(
                    'game_users.*',
                    'game_intakes.code as intake_code',
                    'game_intakes.name as intake_name',
                    'game_intakes.active_week as intake_active_week'
                )
                ->first();

            if ($gameUser) {
                $displayName = $gameUser->display_name ?: trim(($gameUser->preferred_name ?: $gameUser->first_name) . ' ' . $gameUser->surname);
                $languages = [];
                if (! empty($gameUser->languages)) {
                    $decodedLanguages = json_decode($gameUser->languages, true);
                    $languages = is_array($decodedLanguages) ? $decodedLanguages : [];
                }

                $gameRoleName = $gameUser->game_role ?: 'Member';
                $gameRole = DB::table('roles')->whereRaw('LOWER(name) = ?', [strtolower($gameRoleName)])->first();

                return response()->json([
                    "outcome" => 'SUCCESS: Existing game user successfully extracted.',
                    "id" => intval($gameUser->id),
                    "profile_image" => $gameUser->profile_image,
                    "custno" => 900000 + intval($gameUser->id),
                    "role_id" => $gameRole ? intval($gameRole->id) : null,
                    "role_name" => $gameRoleName,
                    "status" => strtoupper($gameUser->game_status),
                    "is_system_user" => false,
                    "is_game_user" => true,
                    "identity_type" => 'student',
                    "game_user_id" => intval($gameUser->id),
                    "game_intake_id" => intval($gameUser->intake_id),
                    "game_intake_code" => $gameUser->intake_code,
                    "game_intake_name" => $gameUser->intake_name,
                    "game_active_week" => $gameUser->intake_active_week,
                    "action_locked_until" => $gameUser->action_locked_until,
                    "action_locked_reason" => $gameUser->action_locked_reason,
                    "action_locked_by_game_user_id" => $gameUser->action_locked_by_game_user_id,
                    "email" => $gameUser->email,
                    "name" => $displayName,
                    "company_name" => $gameUser->intake_name,
                    "gender" => $gameUser->gender,
                    "location" => $gameUser->location,
                    "languages" => $languages,
                    "address_1" => null,
                    "address_2" => null,
                    "address_3" => null,
                    "city" => $gameUser->city,
                    "state" => $gameUser->state,
                    "postcode" => $gameUser->postcode,
                    "phone_no" => $gameUser->phone_no,
                    "updated_at" => $gameUser->updated_at,
                    "updated_by" => $gameUser->updated_by,
                    "current_time" => $currentDate,
                ], 200)->header('Access-Control-Allow-Origin', '*');
            }
        }

        $table_name = 'users';
        $table_name_roles = 'roles';
        $role_id = 3;
        $role_name = 'member';

        // Check if user exists
        if ($method === 'Google OAuth') {
            $userExists = DB::table($table_name)->where('email', $email)->exists();
        } elseif ($method === 'via User ID') {
            $userExists = DB::table($table_name)->where('id', $id)->exists();
        } else {
            return response()->json(['error' => 'Invalid method'], 400);
        }

        if ($userExists) {
            // Fetch user data as an object (stdClass)
            $userResult = DB::table($table_name);
            if ($method === 'Google OAuth') {
                $userResult = $userResult->where('email', $email)->first();
            } elseif ($method === 'via User ID') {
                $userResult = $userResult->where('id', $id)->first();
            }

            if ($userResult) {
                $role_id = $userResult->role_id ?? 3;
                if ($role_id === null) {
                    $role_id = 3;
                    $role_name = 'member';
                }

                // Fetch role name correctly
                $roleResult = DB::table($table_name_roles)->where('id', $role_id)->first();
                if ($roleResult) {
                    $role_name = $roleResult->name;
                }

                // Build response
                $array = [
                    "outcome"       => 'SUCCESS: Existing user successfully extracted.',
                    "id"            => intval($userResult->id),
                    "profile_image" => $userResult->profile_image,
                    "custno"        => intval($userResult->custno),
                    "role_id"       => intval($role_id),
                    "role_name"     => $role_name,
                    "status"        => $userResult->status,
                    "is_system_user" => (bool) ($userResult->is_system_user ?? false),
                    "is_game_user"   => (bool) ($userResult->is_game_user ?? false),
                    "identity_type"  => 'staff',
                    "email"         => $userResult->email,
                    "name"          => $userResult->name,
                    "company_name"  => $userResult->company_name,
                    "gender"        => $userResult->gender,
                    "location"      => $userResult->location,
                    "address_1"     => $userResult->address_1,
                    "address_2"     => $userResult->address_2,
                    "address_3"     => $userResult->address_3,
                    "city"          => $userResult->city,
                    "state"         => $userResult->state,
                    "postcode"      => $userResult->postcode,
                    "phone_no"      => $userResult->phone_no,
                    "updated_at"    => $userResult->updated_at,
                    "updated_by"    => $userResult->updated_by,
                ];
                return response()->json($array, 200)->header('Access-Control-Allow-Origin', '*');
            }
        }

        if ($method === 'Google OAuth' && $email) {
            $gameUser = DB::table('game_users')
                ->leftJoin('game_intakes', 'game_users.intake_id', '=', 'game_intakes.id')
                ->where('game_users.email', $email)
                ->select(
                    'game_users.*',
                    'game_intakes.code as intake_code',
                    'game_intakes.name as intake_name',
                    'game_intakes.active_week as intake_active_week'
                )
                ->first();

            if ($gameUser) {
                $displayName = $gameUser->display_name ?: trim(($gameUser->preferred_name ?: $gameUser->first_name) . ' ' . $gameUser->surname);
                $languages = [];
                if (! empty($gameUser->languages)) {
                    $decodedLanguages = json_decode($gameUser->languages, true);
                    $languages = is_array($decodedLanguages) ? $decodedLanguages : [];
                }
                $gameRoleName = $gameUser->game_role ?: 'Member';
                $gameRole = DB::table('roles')->whereRaw('LOWER(name) = ?', [strtolower($gameRoleName)])->first();

                return response()->json([
                    "outcome" => 'SUCCESS: Existing game user successfully extracted.',
                    "id" => intval($gameUser->id),
                    "profile_image" => $gameUser->profile_image,
                    "custno" => 900000 + intval($gameUser->id),
                    "role_id" => $gameRole ? intval($gameRole->id) : null,
                    "role_name" => $gameRoleName,
                    "status" => $gameUser->game_status,
                    "is_system_user" => false,
                    "is_game_user" => true,
                    "identity_type" => 'student',
                    "game_user_id" => intval($gameUser->id),
                    "game_intake_id" => intval($gameUser->intake_id),
                    "game_intake_code" => $gameUser->intake_code,
                    "game_intake_name" => $gameUser->intake_name,
                    "game_active_week" => $gameUser->intake_active_week,
                    "action_locked_until" => $gameUser->action_locked_until,
                    "action_locked_reason" => $gameUser->action_locked_reason,
                    "action_locked_by_game_user_id" => $gameUser->action_locked_by_game_user_id,
                    "email" => $gameUser->email,
                    "name" => $displayName,
                    "company_name" => $gameUser->intake_name,
                    "gender" => $gameUser->gender,
                    "location" => $gameUser->location,
                    "languages" => $languages,
                    "address_1" => null,
                    "address_2" => null,
                    "address_3" => null,
                    "city" => $gameUser->city,
                    "state" => $gameUser->state,
                    "postcode" => $gameUser->postcode,
                    "phone_no" => $gameUser->phone_no,
                    "updated_at" => $gameUser->updated_at,
                    "updated_by" => $gameUser->updated_by,
                    "current_time" => $currentDate,
                ], 200)->header('Access-Control-Allow-Origin', '*');
            }
        }

        return response()->json([
            'outcome' => 'FAIL: User not found',
            'email_exists' => false,
            'email' => $email,
            'current_time' => $currentDate,
        ], 200)->header('Access-Control-Allow-Origin', '*');
    }















    // Move this function to a proper method within the controller


    public function F0_VMD_login_user(Request $request)
    {
        // IJV - 2025.03.01 - Get the geolocation data
        $clientIp = $this->resolveClientIpAddresses($request);
        $realIp = $clientIp['ip_address'];
        $ipAddressV4 = $clientIp['ip_address_v4'];
        $ipAddressV6 = $clientIp['ip_address_v6'];

        $clientReportedIpV4 = $this->normalizeIpAddress($request->input('vmd_ip_address_v4'));
        $clientReportedIpV6 = $this->normalizeIpAddress($request->input('vmd_ip_address_v6'));

        if ($clientReportedIpV4 && $clientReportedIpV4['ip_address_v4']) {
            $ipAddressV4 = $clientReportedIpV4['ip_address_v4'];
        }

        if ($clientReportedIpV6 && $clientReportedIpV6['ip_address_v6']) {
            $ipAddressV6 = $clientReportedIpV6['ip_address_v6'];
        }

        if ($ipAddressV4 || $ipAddressV6) {
            $realIp = $ipAddressV4 ?: $ipAddressV6;
        }

        $isLoopbackIp = in_array($realIp, ['127.0.0.1', '::1', '0:0:0:0:0:0:0:1'], true);

        if ($isLoopbackIp) {
            $realIp = '127.0.0.1';
            $lxCountry = 'AU';
            $lxRegion = 'Queensland';
            $lxCity = 'Gold Coast';
            $lxZipCode = null;
            $lxTimezone = null;
        } else {
            $accessToken = '4af1c2308a696c';
            $locationLookupIp = $realIp;
            $apiUrl = "http://ipinfo.io/{$locationLookupIp}/json?token={$accessToken}";
            $pageContent = file_get_contents($apiUrl);
            if ($pageContent === false) {
                return response()->json(['errors' => "Failed to fetch geolocation data during F0_VMD_login_user() for {$locationLookupIp}."], 500);
            }
            $parsedJson = json_decode($pageContent);
            $lxCountry     = $parsedJson->country ?? null;
            $lxRegion      = $parsedJson->region ?? null;
            $lxCity        = $parsedJson->city ?? null;
            $lxZipCode     = $parsedJson->postal ?? null;
            $lxTimezone    = $parsedJson->timezone ?? null;
        }

        // Extract request data
        $email     = $request->input('email');
        $password  = $request->input('password');
        $name      = $request->input('name');
        $picture   = $request->input('picture');
        $username  = $request->input('username');
        $google_id = $request->input('google_id');
        $method    = $request->input('method');
        $loginContext = $request->input('login_context', 'staff');
        $currentDate = Carbon::now();
        $userAgent = $request->header('User-Agent');

        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $table_name = 'users';
        $login_log_table = 'user_login_history';

        $emailExists = DB::table($table_name)->where('email', $email)->exists();

        // Google OAuth is lookup-only. Staff and student records must already exist.
        if (! $emailExists && $method === "Google OAuth") {
            return response()->json([
                'outcome' => 'LOGIN_DENIED',
                'errors' => 'This Google account is not registered for staff access or a class intake.',
            ], 403);
        }

        // --- Existing user login ---
        if ($emailExists) {
            // if ($method != "Google OAuth") {
            //     return response()->json([
            //         'outcome' => 'Login attempt failed.',
            //         'email_exists' => $emailExists,
            //     ], 422);
            // }

            $user = DB::table($table_name)->where('email', $email)->first();

            if (! $user) {
                return response()->json([
                    'outcome' => 'FAIL',
                    'email_exists' => 'false',
                    'error' => 'Invalid credentials',
                ], 401);
            }

            // Manual login password validation
            if (! is_null($password) && is_null($google_id) && ! Hash::check($request->password, $user->password)) {
                return response()->json(['error' => 'Invalid password'], 401);
            }

            if ($loginContext === 'student') {
                return response()->json([
                    'outcome' => 'STAFF_ACCOUNT_USED_ON_STUDENT_LOGIN',
                    'errors' => 'This is a staff account. Please use Staff Login.',
                ], 403);
            }

            if ($user->status === 'BANNED') {
                return response()->json([
                    'errors' => 'Your account has been banned.'
                ], 403);
            }

            // ✅ Send 2FA test email for manual login
            // if ($method !== "Google OAuth") {
            //     Mail::to('ivanvetsich@gmail.com')->send(new Test2FAMail());
            // }

            // Retrieve additional fields
            $role = $user->role_name ?? 'member';
            $custno = $user->id + 100000;
            $name = $user->name ?? 'Guest';

            // Log the login
            DB::table($login_log_table)->insert([
                    'email'         => $email,
                    'custno'        => $custno,
                    'name'          => $name,
                    'login_identity_type' => 'staff',
                    'created_at'    => now(),
                'ip_address'    => $realIp,
                'ip_address_v4' => $ipAddressV4,
                'ip_address_v6' => $ipAddressV6,
                'user_country'  => $lxCountry,
                'user_region'   => $lxRegion,
                'user_city'     => $lxCity,
                'user_ZipCode'  => $lxZipCode,
                'user_timezone' => $lxTimezone,
                'user_agent'    => $userAgent,
            ]);


            // ✅ Only Send 2FA Authentication email for manual logins when enabled in Dashboard settings
            if ($method !== "Google OAuth" && $this->dashboardSettingBoolean('login_2fa_enabled', true)) {
                try {
                    $this->sendTwoFactorCodeForIdentity('staff', (int) $user->id, $user->email);
                } catch (\RuntimeException $e) {
                    return response()->json([
                        'outcome' => 'ERROR: ' . $e->getMessage(),
                    ], 500);
                }

                return response()->json([
                    'outcome' => '2FA_REQUIRED',
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'identity_type' => 'staff',
                ]);
            }

            return response()->json([
                'outcome' => 'SUCCESS: Existing user successfully extracted.',
                'id' => $user->id,
                'custno' => $custno,
                'profile_image' => $user->profile_image,
                'google_id' => $google_id,
                'role' => $role,
                'email' => $user->email,
                'name' => $user->name,
                'user_agent' => $userAgent,
            ], 200);
        }

        if ($loginContext === 'student') {
            $gameUser = DB::table('game_users')
                ->join('game_intakes', 'game_users.intake_id', '=', 'game_intakes.id')
                ->where('game_users.email', $email)
                ->select(
                    'game_users.*',
                    'game_intakes.code as intake_code',
                    'game_intakes.name as intake_name',
                    'game_intakes.active_week as intake_active_week'
                )
                ->first();

            if ($gameUser) {
                if (! $gameUser->password || ! Hash::check($request->password, $gameUser->password)) {
                    return response()->json([
                        'outcome' => 'STUDENT_INVALID_PASSWORD',
                        'errors' => 'Invalid student email or password.',
                    ], 401);
                }

                $gameUserStatus = strtoupper((string) $gameUser->game_status);

                if ($gameUserStatus === 'BANNED' && $this->paneOneBlocksBannedLogin($gameUser)) {
                    return response()->json([
                        'outcome' => 'STUDENT_LOGIN_DENIED',
                        'errors' => 'Your class intake account has been banned.',
                    ], 403);
                }

                if ($gameUserStatus === 'DELETED') {
                    return response()->json([
                        'outcome' => 'STUDENT_LOGIN_DENIED',
                        'errors' => 'Your class intake account has been deleted.',
                    ], 403);
                }

                $studentCustno = 900000 + (int) $gameUser->id;
                $studentName = $gameUser->display_name ?: trim(($gameUser->preferred_name ?: $gameUser->first_name) . ' ' . $gameUser->surname);

                if ($this->dashboardSettingBoolean('login_2fa_enabled', true)) {
                    try {
                        $this->sendTwoFactorCodeForIdentity('student', (int) $gameUser->id, $gameUser->email);
                    } catch (\RuntimeException $e) {
                        return response()->json([
                            'outcome' => 'ERROR: ' . $e->getMessage(),
                        ], 500);
                    }

                    cache()->put("2fa_pending_student_login_{$gameUser->id}", [
                        'real_ip' => $realIp,
                        'ip_address_v4' => $ipAddressV4,
                        'ip_address_v6' => $ipAddressV6,
                        'user_country' => $lxCountry,
                        'user_region' => $lxRegion,
                        'user_city' => $lxCity,
                        'user_ZipCode' => $lxZipCode,
                        'user_timezone' => $lxTimezone,
                        'user_agent' => $userAgent,
                    ], now()->addMinutes(10));

                    return response()->json([
                        'outcome' => '2FA_REQUIRED',
                        'user_id' => $gameUser->id,
                        'email' => $gameUser->email,
                        'identity_type' => 'student',
                    ]);
                }

                DB::table('game_users')->where('id', $gameUser->id)->update([
                    'last_login_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table($login_log_table)->insert([
                    'email'         => $email,
                    'custno'        => $studentCustno,
                    'name'          => $studentName,
                    'login_identity_type' => 'student',
                    'created_at'    => now(),
                    'ip_address'    => $realIp,
                    'ip_address_v4' => $ipAddressV4,
                    'ip_address_v6' => $ipAddressV6,
                    'user_country'  => $lxCountry,
                    'user_region'   => $lxRegion,
                    'user_city'     => $lxCity,
                    'user_ZipCode'  => $lxZipCode,
                    'user_timezone' => $lxTimezone,
                    'user_agent'    => $userAgent,
                ]);

                return response()->json([
                    'outcome' => 'SUCCESS: Student game user successfully extracted.',
                    'id' => $gameUser->id,
                    'custno' => $studentCustno,
                    'profile_image' => $gameUser->profile_image,
                    'google_id' => null,
                    'role' => $gameUser->game_role,
                    'role_name' => $gameUser->game_role,
                    'email' => $gameUser->email,
                    'name' => $studentName,
                    'username' => $gameUser->email,
                    'identity_type' => 'student',
                    'is_system_user' => false,
                    'is_game_user' => true,
                    'game_user_id' => $gameUser->id,
                    'game_intake_id' => $gameUser->intake_id,
                    'game_intake_code' => $gameUser->intake_code,
                    'game_intake_name' => $gameUser->intake_name,
                    'game_active_week' => $gameUser->intake_active_week,
                    'must_change_password' => (int) $gameUser->must_change_password === 1,
                    'user_agent' => $userAgent,
                ], 200);
            }

            return response()->json([
                'outcome' => 'STUDENT_ACCOUNT_NOT_FOUND',
                'errors' => 'No class intake account was found for this email address.',
            ], 404);
        }

        // Catch-all fallback
        return response()->json([
            'outcome' => 'Login attempt failed.',
            'email_exists' => $emailExists,
        ], 422);
    }














    public function VMD_get_user_permissions(Request $request)
    {
        // $email = $request->query('email');
        $email = $request->input('email');


        if (!$email) {
            return response()->json(['error' => 'Missing email parameter.'], 400);
        }

        $user = User::where('email', $email)->first();
        $role = null;

        if ($user) {
            if ($user->role_id) {
                $role = Role::where('id', $user->role_id)->first();
            }

            if (! $role && $user->role_name) {
                $role = Role::whereRaw('LOWER(name) = ?', [strtolower($user->role_name)])->first();
            }
        }

        if (! $role) {
            $gameUser = DB::table('game_users')->where('email', $email)->first();

            if ($gameUser && $gameUser->game_role) {
                $role = Role::whereRaw('LOWER(name) = ?', [strtolower($gameUser->game_role)])->first();
            }
        }

        if (! $role) {
            return response()->json(['permissions' => []]);
        }

        $permissions = DB::table('role_has_permissions')
            ->join('permissions', 'role_has_permissions.permission_id', '=', 'permissions.id')
            ->where('role_has_permissions.role_id', $role->id)
            ->select('permissions.name')
            ->get();

        $caslFormatted = $permissions->map(function ($perm) {
            $parts = explode(' ', strtolower($perm->name));
            return [
                'action' => $parts[0] ?? '',
                'subject' => $parts[1] ?? ''
            ];
        });

        return response()->json(['permissions' => $caslFormatted]);
    }












    // Move this function to a proper method within the controller

    public function F0_VMD_ban_user(Request $request)
    {
        // return;
        //  But let me do it if I'm logged in as me,,,
        $response = [];

        // Validate and sanitize input
        $validator = Validator::make($request->all(), [
            'id'           => 'required|integer',
            'updated_by'   => 'required|string|max:255', // Ensure updated_by is provided
            'vmd_audit_reason' => 'required|string|max:255',
            'vmd_user_name' => 'required|string|max:255',
            'vmd_user_email' => 'required|string|max:255',
        ]);

        // If validation fails, return errors
        if ($validator->fails()) {
            $response['outcome'] = "FAIL";
            $response['message'] = "The updated_by field is required.";
            return response()->json($response, 422);
        }

        // Extract validated data
        $data = $validator->validated();
        if ($timeoutResponse = $this->userManagementTimeoutResponse($data['vmd_user_email'])) {
            return $timeoutResponse;
        }

        // Update the user record based on custno
        $user = User::where('id', $data['id'])->first();

        if ($user) {
            $targetWasActive = ! in_array(strtoupper((string) $user->status), ['BANNED', 'DELETED'], true);

            if (! $this->canBanStaffUser($data['vmd_user_email'], $user)) {
                if ($this->isStaffAdmin($user)) {
                    $this->logProtectedAccountEditBlocked($request, $data, $data['vmd_audit_reason'] ?? 'ban protected account');
                    $response['message'] = "Permission Denied: Staff Admin users cannot be banned.";
                } else {
                    $response['message'] = "Permission Denied: You do not have permission to ban this Staff user.";
                }

                $response['outcome'] = "FAIL";
                return response()->json($response, 403);
            }

            $actorActionLockedUntil = $this->lockActorGameUserIfNeeded(
                $data['vmd_user_email'],
                $targetWasActive,
                'Banned a Staff user'
            );

            $user->status       = "BANNED";
            $user->updated_by   = $data['updated_by'] ?? $user->updated_by;
            $user->save();


            // Fetch the real user IP from Cloudflare
            $realIp = $request->header('X-Forwarded-For');
            $realIp = $realIp ? explode(',', $realIp)[0] : $request->ip();



            // Log the update action in user_audit_history
            DB::table('user_audit_history')->insert([
                'custno' => $data['id'] + 100000,
                'dteprfmd' => now(),
                'comments' => $data['vmd_audit_reason'],
                'clerk_id' => $data['vmd_user_name'],
                'created_by_email' => $data['vmd_user_email'],
                'created_by_ip_address' => $this->getRequestIpAddress($request),
                'created_at' => now(),
                'updated_at' => now(),
            ]);


            // Prepare response data
            $response['outcome'] = "SUCCESS";
            $response['message'] = "User Banned successfully.";
            $response['actor_action_locked_until'] = $actorActionLockedUntil
                ? $actorActionLockedUntil->toDateTimeString()
                : null;
            $response['updated_details'] = [
                "id"            => intval($user->id),
                "custno"        => intval($user->custno),
                "email"         => $user->email,
                "name"          => $user->name,
                "company_name"  => $user->company_name,
                "gender"        => $user->gender,
                "location"      => $user->location,
                "address_1"     => $user->address_1,
                "address_2"     => $user->address_2,
                "address_3"     => $user->address_3,
                "city"          => $user->city,
                "role_id"       => intval($user->role_id),
                "role_name"     => $user->role_name,
                "is_system_user" => (bool) $user->is_system_user,
                "is_game_user"   => (bool) $user->is_game_user,
                "identity_type"  => 'staff',
                "postcode"      => $user->postcode,
                "phone_no"      => $user->phone_no,
                "profile_image" => $user->profile_image,
                "state"         => $user->state,
                "status"        => $user->status,
                "updated_at"    => $user->updated_at,
                "updated_by"    => $user->updated_by, // Include updated_by in the response
            ];
        } else {
            $response['outcome'] = false;
            $response['message'] = "User not found.";
            return response()->json($response, 404);
        }

        return response()->json($response, 200);
    }


    public function F0_VMD_unbanUser(Request $request)
    {
        $response = [];

        $validator = Validator::make($request->all(), [
            'id'           => 'required|integer',
            'updated_by'   => 'required|string|max:255',
            'vmd_audit_reason' => 'required|string|max:255',
            'vmd_user_name' => 'required|string|max:255',
            'vmd_user_email' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            $response['outcome'] = "FAIL";
            $response['message'] = "The updated_by field is required.";
            return response()->json($response, 422);
        }

        $data = $validator->validated();
        if ($timeoutResponse = $this->userManagementTimeoutResponse($data['vmd_user_email'])) {
            return $timeoutResponse;
        }

        $user = User::where('id', $data['id'])->first();

        if (!$user) {
            $response['outcome'] = false;
            $response['message'] = "User not found.";
            return response()->json($response, 404);
        }

        if (! $this->canUnbanStaffUser($data['vmd_user_email'], $user)) {
            $response['outcome'] = "FAIL";
            $response['message'] = $this->actorGameUser($data['vmd_user_email'])
                ? "Permission Denied: Students cannot unban Staff users."
                : "Permission Denied: You do not have permission to unban this Staff user.";
            return response()->json($response, 403);
        }

        $previousStatus = strtoupper((string) $user->status);

        if (in_array($previousStatus, ['BANNED', 'DELETED'], true)) {
            $user->status = 'Active';
            $user->updated_by = $data['updated_by'] ?? $user->updated_by;
            $user->save();

            $realIp = $request->header('X-Forwarded-For');
            $realIp = $realIp ? explode(',', $realIp)[0] : $request->ip();

            DB::table('user_audit_history')->insert([
                'custno' => $data['id'] + 100000,
                'dteprfmd' => now(),
                'comments' => $data['vmd_audit_reason'],
                'clerk_id' => $data['vmd_user_name'],
                'created_by_email' => $data['vmd_user_email'],
                'created_by_ip_address' => $this->getRequestIpAddress($request),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $wasDeleted = $previousStatus === 'DELETED';
            $this->createPersistedNotification([
                'recipient_email' => $user->email,
                'actor_email' => $data['vmd_user_email'],
                'type' => 'info',
                'title' => $wasDeleted ? 'Account undeleted' : 'Account unbanned',
                'message' => $wasDeleted
                    ? "Your account was undeleted by {$data['vmd_user_name']}."
                    : "Your account was unbanned by {$data['vmd_user_name']}.",
                'source' => 'user-management',
                'dedupe_key' => 'staff-' . ($wasDeleted ? 'undeleted' : 'unbanned') . '-' . intval($user->id) . '-' . now()->format('YmdHis'),
                'metadata' => [
                    'user_id' => intval($user->id),
                    'identity_type' => 'staff',
                    'previous_status' => $previousStatus,
                    'status' => 'Active',
                ],
            ]);
        }

        $response['outcome'] = "SUCCESS";
        $response['message'] = "User Unbanned successfully.";
        $response['updated_details'] = [
            "id"            => intval($user->id),
            "custno"        => intval($user->custno),
            "email"         => $user->email,
            "name"          => $user->name,
            "company_name"  => $user->company_name,
            "gender"        => $user->gender,
            "location"      => $user->location,
            "address_1"     => $user->address_1,
            "address_2"     => $user->address_2,
            "address_3"     => $user->address_3,
            "city"          => $user->city,
            "role_id"       => intval($user->role_id),
            "role_name"     => $user->role_name,
            "is_system_user" => (bool) $user->is_system_user,
            "is_game_user"   => (bool) $user->is_game_user,
            "postcode"      => $user->postcode,
            "phone_no"      => $user->phone_no,
            "profile_image" => $user->profile_image,
            "state"         => $user->state,
            "status"        => $user->status,
            "updated_at"    => $user->updated_at,
            "updated_by"    => $user->updated_by,
        ];

        return response()->json($response, 200);
    }
















    // Move this function to a proper method within the controller

    public function F0_VMD_delete_user(Request $request)
    {
        // return;
        //  But let me do it if I'm logged in as me,,,
        $response = [];

        // Validate and sanitize input
        $validator = Validator::make($request->all(), [
            'id'           => 'required|integer',
            'updated_by'   => 'required|string|max:255', // Ensure updated_by is provided
            'vmd_audit_reason' => 'required|string|max:255',
            'vmd_user_name' => 'required|string|max:255',
            'vmd_user_email' => 'required|string|max:255',
        ]);

        // If validation fails, return errors
        if ($validator->fails()) {
            $response['outcome'] = "FAIL";
            $response['message'] = "The updated_by field is required.";
            return response()->json($response, 422);
        }

        // Extract validated data
        $data = $validator->validated();
        if ($timeoutResponse = $this->userManagementTimeoutResponse($data['vmd_user_email'])) {
            return $timeoutResponse;
        }

        // Update the user record based on custno
        $user = User::where('id', $data['id'])->first();

        if ($user) {
            if (! $this->canDeleteStaffUser($data['vmd_user_email'], $user)) {
                if ($this->isStaffAdmin($user)) {
                    $this->logProtectedAccountEditBlocked($request, $data, $data['vmd_audit_reason'] ?? 'delete protected account');
                    $response['message'] = "Permission Denied: Staff Admin users cannot be deleted.";
                } elseif ($this->actorGameUser($data['vmd_user_email'])) {
                    $response['message'] = "Permission Denied: Students cannot delete Staff users.";
                } else {
                    $response['message'] = "Permission Denied: You do not have permission to delete this Staff user.";
                }

                $response['outcome'] = "FAIL";
                return response()->json($response, 403);
            }

            $user->status       = "DELETED";
            $user->updated_by   = $data['updated_by'] ?? $user->updated_by;
            $user->save();


            // Fetch the real user IP from Cloudflare
            $realIp = $request->header('X-Forwarded-For');
            $realIp = $realIp ? explode(',', $realIp)[0] : $request->ip();




            // Log the update action in user_audit_history
            DB::table('user_audit_history')->insert([
                'custno' =>  $data['id'] + 100000,
                'dteprfmd' => now(),
                'comments' => 'User ' . $data['id'] . ' has been DELETED',
                'clerk_id' => $data['vmd_user_name'],
                'created_by_email' => $data['vmd_user_email'],
                'created_by_ip_address' => $this->getRequestIpAddress($request),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->createPersistedNotification([
                'recipient_email' => $user->email,
                'actor_email' => $data['vmd_user_email'],
                'type' => 'error',
                'title' => 'Account deleted',
                'message' => "Your account was deleted by {$data['vmd_user_name']}.",
                'source' => 'user-management',
                'dedupe_key' => 'staff-deleted-' . intval($user->id) . '-' . now()->format('YmdHis'),
                'metadata' => [
                    'user_id' => intval($user->id),
                    'identity_type' => 'staff',
                    'status' => 'DELETED',
                ],
            ]);


            // Prepare response data
            $response['outcome'] = "SUCCESS";
            $response['message'] = "User Banned successfully.";
            $response['updated_details'] = [
                "id"            => intval($user->id),
                "custno"        => intval($user->custno),
                "email"         => $user->email,
                "name"          => $user->name,
                "company_name"  => $user->company_name,
                "gender"        => $user->gender,
                "location"      => $user->location,
                "address_1"     => $user->address_1,
                "address_2"     => $user->address_2,
                "address_3"     => $user->address_3,
                "city"          => $user->city,
                "role_id"       => intval($user->role_id),
                "role_name"     => $user->role_name,
                "is_system_user" => (bool) $user->is_system_user,
                "is_game_user"   => (bool) $user->is_game_user,
                "postcode"      => $user->postcode,
                "phone_no"      => $user->phone_no,
                "profile_image" => $user->profile_image,
                "state"         => $user->state,
                "status"        => $user->status,
                "updated_at"    => $user->updated_at,
                "updated_by"    => $user->updated_by, // Include updated_by in the response
            ];
        } else {
            $response['outcome'] = false;
            $response['message'] = "User not found.";
            return response()->json($response, 404);
        }

        return response()->json($response, 200);
    }

    public function F0_VMD_permanently_delete_user(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer',
            'updated_by' => 'required|string|max:255',
            'vmd_audit_reason' => 'nullable|string|max:255',
            'vmd_user_name' => 'required|string|max:255',
            'vmd_user_email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Validation failed.',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $data = $validator->validated();

        if (! $this->canRestoreDeletedUsers($data['vmd_user_email'])) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Permission Denied: Staff Admin or Staff Protector access required to permanently delete users.',
            ], 403);
        }

        $user = User::where('id', $data['id'])->first();

        if (! $user) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'User not found.',
            ], 404);
        }

        if ((int) $user->id === 1 || strcasecmp((string) $user->email, 'admin@velodata.org') === 0) {
            $this->logProtectedAccountEditBlocked($request, $data, $data['vmd_audit_reason'] ?? 'permanently delete protected account');

            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Permission Denied: The system Admin account cannot be permanently deleted.',
            ], 403);
        }

        if ($this->isStaffAdmin($user)) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Permission Denied: Staff Admin users cannot be permanently deleted.',
            ], 403);
        }

        if (strcasecmp((string) $user->status, 'DELETED') !== 0) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Only users already marked DELETED can be permanently deleted.',
            ], 409);
        }

        DB::table('user_audit_history')->insert([
            'custno' => $user->id + 100000,
            'dteprfmd' => now(),
            'comments' => $this->permanentDeleteAuditComment(
                $data['vmd_audit_reason'] ?: 'User permanently deleted',
                $user->name,
                $user->email
            ),
            'clerk_id' => $data['vmd_user_name'],
            'created_by_email' => $data['vmd_user_email'],
            'created_by_ip_address' => $this->getRequestIpAddress($request),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user->delete();

        return response()->json([
            'outcome' => 'SUCCESS',
            'message' => 'User permanently deleted successfully.',
            'id' => intval($data['id']),
        ], 200);
    }









    public function F0_VMD_get_login_history(Request $request)
    {
        // Validate email input
        $request->validate([
            'email' => 'required|email',
            'method' => 'required',
        ]);

        $email = $request->input('email');
        $method = $request->input('method');
        $gameIntakeId = $request->input('game_intake_id') ?: $request->query('game_intake_id');
        $gameIntakeCode = $request->input('game_intake_code') ?: $request->query('game_intake_code');
        $ipAddressFilter = trim((string) ($request->input('ip_address') ?: $request->query('ip_address') ?: ''));
        $viewer = DB::table('users')->where('email', $email)->first();
        $viewerGameUser = $viewer ? null : DB::table('game_users')->where('email', $email)->first();
        $canViewSpyLoginRows =
            ($viewer && strcasecmp((string) $viewer->role_name, 'Protector') === 0) ||
            ($viewerGameUser && strcasecmp((string) $viewerGameUser->game_role, 'Protector') === 0);
        $historyIntakeScope = $this->historyIntakeScope($email, $gameIntakeId, $gameIntakeCode);

        // Get the total number of records in the table
        $recordsTotal = DB::table('user_login_history')->count();
        $newLoginHistoryQuery = function () {
            return DB::table('user_login_history')
                ->leftJoin('users', 'user_login_history.email', '=', 'users.email')
                ->leftJoin('game_users', 'user_login_history.email', '=', 'game_users.email')
                ->leftJoin('game_intakes', 'game_users.intake_id', '=', 'game_intakes.id');
        };
        $applySpyLoginVisibility = function ($query) use ($canViewSpyLoginRows) {
            if ($canViewSpyLoginRows) {
                return $query;
            }

            return $query->where(function ($query) {
                $query
                    ->whereNull(DB::raw('COALESCE(users.role_name, game_users.game_role)'))
                    ->orWhereRaw('LOWER(COALESCE(users.role_name, game_users.game_role)) <> ?', ['spy']);
            });
        };
        $applyIpAddressFilter = function ($query) use ($ipAddressFilter) {
            if ($ipAddressFilter === '') {
                return $query;
            }

            return $query->where(function ($query) use ($ipAddressFilter) {
                $query
                    ->where('user_login_history.ip_address_v4', $ipAddressFilter)
                    ->orWhere('user_login_history.ip_address', $ipAddressFilter);
            });
        };
        $loginHistorySelect = [
            'user_login_history.*',
            'users.google_id',
            DB::raw('COALESCE(users.profile_image, game_users.profile_image) as profile_image'),
            DB::raw('COALESCE(users.role_name, game_users.game_role) as role_name'),
        ];
        // Apply filters based on method
        switch ($method) {
            case 'single user':
                $login_history_list = $applyIpAddressFilter($applySpyLoginVisibility($this->applyHistoryIntakeScope($newLoginHistoryQuery(), $historyIntakeScope)))
                    ->where('user_login_history.email', $email)
                    ->select($loginHistorySelect)
                    ->orderBy('user_login_history.created_at', 'DESC')
                    ->get();

                break;

            case 'Staff Logins':
                $login_history_list = $applyIpAddressFilter($applySpyLoginVisibility($this->applyHistoryIntakeScope($newLoginHistoryQuery(), $historyIntakeScope)))
                    ->where(function ($query) {
                        $query->where('user_login_history.login_identity_type', 'staff')
                            ->orWhereNull('user_login_history.login_identity_type');
                    })
                    ->select($loginHistorySelect)
                    ->orderBy('user_login_history.created_at', 'DESC')
                    ->limit(100)
                    ->get();

                break;

            case 'Student Logins':
                $login_history_list = $applyIpAddressFilter($applySpyLoginVisibility($this->applyHistoryIntakeScope($newLoginHistoryQuery(), $historyIntakeScope)))
                    ->where('user_login_history.login_identity_type', 'student')
                    ->select($loginHistorySelect)
                    ->orderBy('user_login_history.created_at', 'DESC')
                    ->limit(100)
                    ->get();

                break;

            default:
                $login_history_list = $applyIpAddressFilter($applySpyLoginVisibility($this->applyHistoryIntakeScope($newLoginHistoryQuery(), $historyIntakeScope)))
                    ->select($loginHistorySelect)
                    ->orderBy('user_login_history.created_at', 'DESC')
                    ->limit(100)
                    ->get();

                break;
        }

        // Count filtered records
        $recordsFiltered = $login_history_list->count();

        if ($login_history_list->isEmpty()) {
            return response()->json([
                'data' => [],
                'recordsFiltered' => 0,
                'recordsTotal' => $recordsTotal,
            ], 200);
        }

        // Transform data using collections
        $login_history_array = $login_history_list->map(function ($row) {
            return [
                "type" => 'user_login_history',
                "id" => $row->id,
                'attributes' => [
                    "custno" => $row->custno,
                    "email" => $row->email,
                    "name" => $row->name,
                    "login_identity_type" => $row->login_identity_type ?? 'staff',
                    "ip_address" => $row->ip_address,
                    "ip_address_v4" => $row->ip_address_v4,
                    "ip_address_v6" => $row->ip_address_v6,
                    "user_city" => $row->user_city,
                    "user_region" => $row->user_region,
                    "user_country" => $row->user_country,
                    "user_ZipCode" => $row->user_ZipCode,
                    "user_agent" => $row->user_agent,
                    "created_at" => $row->created_at,
                    "google_id" => $row->google_id,
                    "profile_image" => $row->profile_image ?? null,
                    "role_name" => $row->role_name,
                ],
            ];
        });

        // Return the response
        return response()->json([
            'data' => $login_history_array,
            'recordsFiltered' => $recordsFiltered,
            'recordsTotal' => $recordsTotal,
        ], 200);
    }








    public function F0_VMD_get_audit_history(Request $request)
    {


        // Get the total number of records in the table
        $recordsTotal = DB::table('user_audit_history')->count();
        $requestEmail = $request->input('email') ?: $request->query('email');
        $gameIntakeId = $request->input('game_intake_id') ?: $request->query('game_intake_id');
        $gameIntakeCode = $request->input('game_intake_code') ?: $request->query('game_intake_code');
        $ipAddressFilter = trim((string) ($request->input('ip_address') ?: $request->query('ip_address') ?: ''));
        $viewer = $requestEmail ? DB::table('users')->where('email', $requestEmail)->first() : null;
        $viewerGameUser = ($requestEmail && ! $viewer) ? DB::table('game_users')->where('email', $requestEmail)->first() : null;
        $canViewProtectedSecurityAudits = $viewer && in_array($viewer->role_name, ['Admin', 'Protector', 'Trainer'], true);
        $canViewSpyAuditRows =
            ($viewer && strcasecmp((string) $viewer->role_name, 'Protector') === 0) ||
            ($viewerGameUser && strcasecmp((string) $viewerGameUser->game_role, 'Protector') === 0);
        $historyIntakeScope = $this->historyIntakeScope($requestEmail, $gameIntakeId, $gameIntakeCode);


        $auditHistoryQuery = DB::table('user_audit_history')
            ->leftJoin('users', 'users.id', '=', DB::raw('user_audit_history.custno - 100000'))
            ->leftJoin('game_users', 'game_users.id', '=', DB::raw('user_audit_history.custno - 900000'))
            ->leftJoin('game_intakes', 'game_users.intake_id', '=', 'game_intakes.id')
            ->leftJoin('users as actor_users', 'actor_users.email', '=', 'user_audit_history.created_by_email')
            ->leftJoin('game_users as actor_game_users', 'actor_game_users.email', '=', 'user_audit_history.created_by_email')
            ->select(
                'user_audit_history.*',
                DB::raw("COALESCE(users.name, game_users.display_name, TRIM(CONCAT(COALESCE(game_users.preferred_name, game_users.first_name, ''), ' ', COALESCE(game_users.surname, '')))) as target_name"),
                DB::raw('COALESCE(users.email, game_users.email) as target_email'),
                DB::raw('COALESCE(users.role_name, game_users.game_role) as target_role_name'),
                DB::raw('COALESCE(actor_users.role_name, actor_game_users.game_role) as actor_role_name'),
                DB::raw('COALESCE(actor_users.profile_image, actor_game_users.profile_image) as actor_profile_image')
            );

        $auditHistoryQuery = $this->applyHistoryIntakeScope($auditHistoryQuery, $historyIntakeScope);

        if ($ipAddressFilter !== '') {
            $auditHistoryQuery->where('user_audit_history.created_by_ip_address', $ipAddressFilter);
        }

        if (! $canViewProtectedSecurityAudits) {
            $auditHistoryQuery
                ->where('user_audit_history.comments', 'not like', 'Protected account edit blocked:%')
                ->where('user_audit_history.comments', 'not like', 'Protected account warning;%');
        }

        if (! $canViewSpyAuditRows) {
            $auditHistoryQuery->where(function ($query) {
                $query
                    ->whereNull(DB::raw('COALESCE(users.role_name, game_users.game_role)'))
                    ->orWhereRaw('LOWER(COALESCE(users.role_name, game_users.game_role)) <> ?', ['spy']);
            })->where(function ($query) {
                $query
                    ->whereNull(DB::raw('COALESCE(actor_users.role_name, actor_game_users.game_role)'))
                    ->orWhereRaw('LOWER(COALESCE(actor_users.role_name, actor_game_users.game_role)) <> ?', ['spy']);
            });
        }

        $audit_history_list = $auditHistoryQuery
            ->orderBy('user_audit_history.created_at', 'DESC')
            ->limit(100)
            ->get();


        // Count filtered records
        $recordsFiltered = $audit_history_list->count();

        if ($audit_history_list->isEmpty()) {
            return response()->json([
                'data' => [],
                'recordsFiltered' => 0,
                'recordsTotal' => $recordsTotal,
            ], 200);
        }

        // Transform data using collections
        $audit_history_array = $audit_history_list->map(function ($row) {
            $permanentDeleteTarget = $this->permanentDeleteAuditTarget($row->comments);

            return [
                "type" => 'user_audit_history',
                "id" => $row->id, // Now using the actual record number
                'attributes' => [
                    "custno" => $row->custno,
                    "comments" => $row->comments,
                    "created_by_email" => $row->created_by_email,
                    "clerk_id" => $row->clerk_id,
                    "created_by_ip_address" => $row->created_by_ip_address,
                    "target_name" => $row->target_name ?: $permanentDeleteTarget['target_name'],
                    "target_email" => $row->target_email ?: $permanentDeleteTarget['target_email'],
                    "target_role_name" => $row->target_role_name,
                    "actor_role_name" => $row->actor_role_name,
                    "actor_profile_image" => $row->actor_profile_image,
                    // "user_city" => $row->user_city,
                    // "user_region" => $row->user_region,
                    // "user_country" => $row->user_country,
                    // "user_ZipCode" => $row->user_ZipCode,
                    // "user_agent" => $row->user_agent,
                    "created_at" => $row->created_at,
                ],
            ];
        });

        // Return the response
        return response()->json([
            'data' => $audit_history_array,
            'recordsFiltered' => $recordsFiltered,
            'recordsTotal' => $recordsTotal,
        ], 200);
    }










    public function F0_VMD_get_notifications(Request $request)
    {
        $email = $request->input('email') ?: $request->query('email');

        if (! $email) {
            return response()->json(['error' => 'Missing email parameter.'], 400);
        }

        $this->createAuditBackfillNotificationsForEmail($email);

        $notifications = DB::table('user_notifications')
            ->where('recipient_email', $email)
            ->whereNull('dismissed_at')
            ->orderBy('created_at', 'DESC')
            ->limit(50)
            ->get()
            ->map(function ($row) {
                return $this->transformNotificationRow($row);
            });

        return response()->json([
            'data' => $notifications,
            'recordsFiltered' => $notifications->count(),
            'recordsTotal' => DB::table('user_notifications')
                ->where('recipient_email', $email)
                ->whereNull('dismissed_at')
                ->count(),
        ], 200);
    }

    public function F0_VMD_create_notification(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'recipient_email' => 'required|email',
            'actor_email' => 'nullable|email',
            'type' => 'nullable|string|max:50',
            'title' => 'required|string|max:255',
            'message' => 'nullable|string',
            'source' => 'nullable|string|max:100',
            'related_audit_history_id' => 'nullable|integer',
            'dedupe_key' => 'nullable|string|max:255',
            'metadata' => 'nullable',
            'created_at' => 'nullable|string|max:80',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'outcome' => 'FAIL',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $data = $validator->validated();

        $notificationInput = [
            'recipient_email' => $data['recipient_email'],
            'actor_email' => $data['actor_email'] ?? null,
            'type' => $data['type'] ?? 'info',
            'title' => $data['title'],
            'message' => $data['message'] ?? '',
            'source' => $data['source'] ?? 'system',
            'related_audit_history_id' => $data['related_audit_history_id'] ?? null,
            'dedupe_key' => $data['dedupe_key'] ?? null,
            'metadata' => $this->normalizeNotificationMetadata($data['metadata'] ?? []),
            'created_at' => $data['created_at'] ?? now(),
        ];

        if ($this->isProfileWatcherStudentUnbanArtifact($notificationInput)) {
            return response()->json([
                'outcome' => 'SUPPRESSED',
                'message' => 'Profile watcher student unban artifact suppressed.',
                'data' => null,
            ], 200);
        }

        if ($this->isRedundantProfileWatcherUserChangeNotification($notificationInput)) {
            return response()->json([
                'outcome' => 'SUPPRESSED',
                'message' => 'Profile watcher user-change notification suppressed; authoritative notification is created by user management.',
                'data' => null,
            ], 200);
        }

        $notification = $this->createPersistedNotification($notificationInput);

        return response()->json([
            'outcome' => 'SUCCESS',
            'data' => $notification ? $this->transformNotificationRow($notification) : null,
        ], 200);
    }

    public function F0_VMD_mark_notifications_read(Request $request)
    {
        $email = $request->input('email');
        $id = $request->input('id');

        if (! $email) {
            return response()->json(['error' => 'Missing email parameter.'], 400);
        }

        $query = DB::table('user_notifications')
            ->where('recipient_email', $email)
            ->whereNull('read_at');

        if ($id) {
            $query->where('id', $id);
        }

        $updated = $query->update([
            'read_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'outcome' => 'SUCCESS',
            'updated' => $updated,
        ], 200);
    }

    public function F0_VMD_dismiss_notification(Request $request)
    {
        $email = $request->input('email');
        $id = $request->input('id');

        if (! $email || ! $id) {
            return response()->json(['error' => 'Missing email or notification id.'], 400);
        }

        $dismissed = DB::table('user_notifications')
            ->where('recipient_email', $email)
            ->where('id', $id)
            ->whereNull('dismissed_at')
            ->update([
                'dismissed_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'outcome' => 'SUCCESS',
            'dismissed' => $dismissed,
        ], 200);
    }

    public function F0_VMD_clear_notifications(Request $request)
    {
        $email = $request->input('email');

        if (! $email) {
            return response()->json(['error' => 'Missing email parameter.'], 400);
        }

        $dismissed = DB::table('user_notifications')
            ->where('recipient_email', $email)
            ->whereNull('dismissed_at')
            ->update([
                'dismissed_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'outcome' => 'SUCCESS',
            'dismissed' => $dismissed,
        ], 200);
    }

    /******************************************************************************************************************************************
     * IJV - 2025.03.06  -  F0_VMD_updateUser()
     * 
     *                      
     * 
     *******************************************************************************************************************************************/

    public function F0_VMD_updateUser(Request $request)
    {
        $response = [];

        // Validate and sanitize input
        $validator = Validator::make($request->all(), [
            'id'               => 'required|integer',
            'custno'           => 'required|integer',
            'email'            => 'nullable|email',
            'name'             => 'nullable|string|max:255',
            'role_id'          => 'nullable|integer',
            'role_name'        => 'nullable|string|max:255',
            'is_system_user'   => 'nullable|boolean',
            'is_game_user'     => 'nullable|boolean',
            'company_name'     => 'nullable|string|max:255',
            'gender'           => 'nullable|string|max:50',
            'location'         => 'nullable|string|max:255',
            'phone_no'         => 'nullable|string|max:20',
            'password'         => 'nullable|string|max:255',
            'password_confirmation' => 'nullable|string|max:255',
            // 'languages'        => 'nullable|string|max:255',
            'address_1'        => 'nullable|string|max:255',
            'address_2'        => 'nullable|string|max:255',
            'address_3'        => 'nullable|string|max:255',
            'city'             => 'nullable|string|max:255',
            'state'            => 'nullable|string|max:255',
            'postcode'         => 'nullable|string|max:10',
            'updated_by'       => 'required|string|max:255',
            'vmd_audit_reason' => 'required|string|max:255',
            'vmd_user_name'    => 'required|string|max:255',
            'vmd_user_email'   => 'required|string|max:255',
            'game_intake_code'  => 'nullable|string|exists:game_intakes,code',
        ]);

if ($validator->fails()) {
    $response['outcome'] = "FAIL";
    $response['message'] = "Validation failed.";
    $response['errors'] = $validator->errors()->toArray(); // <-- return detailed errors
    return response()->json($response, 409);
}


        // // Validate and sanitize input
        // $validator = Validator::make($request->all(), [
        //     'updated_by'   => 'required|string|max:255',
        //     'vmd_audit_reason' => 'required|string|max:255',
        //     'vmd_user_name' => 'required|string|max:255',
        //     'vmd_user_email' => 'required|string|max:255',
        // ]);

        // if ($validator->fails()) {
        //     $response['outcome'] = "FAIL";
        //     $response['message'] = "The updated_by and vmd_audit_reason fields are required.";
        //     return response()->json($response, 409);
        // }

        // Extract validated data
        $data = $validator->validated();
        $protectedAdminSelfAvatarUpdate = false;
        if ($timeoutResponse = $this->userManagementTimeoutResponse($data['vmd_user_email'])) {
            return $timeoutResponse;
        }

        $actorGameUserForGeoLock = $this->actorGameUser($data['vmd_user_email']);
        $geoLockIntakeId = isset($data['game_intake_code'])
            ? (int) DB::table('game_intakes')->where('code', $data['game_intake_code'])->value('id')
            : ($actorGameUserForGeoLock?->intake_id ? intval($actorGameUserForGeoLock->intake_id) : null);

        if ($geoLockResponse = $this->geoLockUserEditResponse(
            $request,
            $geoLockIntakeId,
            'F0_VMD_updateUser()'
        )) {
            return $geoLockResponse;
        }

        if ($data['id'] === 1) {
            $targetAdmin = User::where('id', 1)->first();
            $protectedAdminSelfAvatarUpdate = $targetAdmin
                && strcasecmp((string) $targetAdmin->email, (string) ($data['vmd_user_email'] ?? '')) === 0
                && strcasecmp((string) ($data['vmd_audit_reason'] ?? ''), 'Profile image updated') === 0;

            if ($protectedAdminSelfAvatarUpdate) {
                // Allow the protected Admin account to update its own avatar.
            } else {
            $this->logProtectedAccountEditBlocked($request, $data, $data['vmd_audit_reason'] ?? 'update protected account');
            $response['outcome'] = "FAIL";
            $response['message'] = "Permission Denied:  (You can NEVER EVER edit the Admin account.)";
            return response()->json($response, 403);
            }
        }

        if ($data['id'] == 1 && ! $protectedAdminSelfAvatarUpdate) {
            return response()->json(['errors' => "Permission Denied:  (You cannot edit the Admin account)"], 403);
        }

        if ($this->nonAdminStaffAssigningAdmin($data['vmd_user_email'], $data['role_id'] ?? null, $data['role_name'] ?? null)) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Only Staff Admins can assign the Admin role.',
            ], 403);
        }

        // Update the user record based on ID
        $user = User::where('id', $data['id'])->first();

        if ($user) {
            $oldRoleName = (string) ($user->role_name ?: optional($user->roles()->first())->name);
            $user->name         = $data['name'] ?? $user->name;
            $user->email        = $data['email'] ?? $user->email;
            $user->role_id      = $data['role_id'] ?? $user->role_id;
            $user->role_name    = $data['role_name'] ?? $user->role_name;
            if (array_key_exists('is_system_user', $data)) {
                $user->is_system_user = (bool) $data['is_system_user'];
            }
            if (array_key_exists('is_game_user', $data)) {
                $user->is_game_user = (bool) $data['is_game_user'];
            }
            $user->company_name = $data['company_name'] ?? $user->company_name;
            $user->gender       = $data['gender'] ?? $user->gender;
            $user->location     = $data['location'] ?? $user->location;
            $user->phone_no     = $data['phone_no'] ?? $user->phone_no;
            $passwordWasChanged = isset($data['password']) && (string) $data['password'] !== '';
            $user->password     = $passwordWasChanged ? bcrypt($data['password']) : $user->password;

            $user->address_1    = $data['address_1'] ?? $user->address_1;
            $user->address_2    = $data['address_2'] ?? $user->address_2;
            $user->address_3    = $data['address_3'] ?? $user->address_3;
            $user->city         = $data['city'] ?? $user->city;
            $user->state        = $data['state'] ?? $user->state;
            $user->postcode     = $data['postcode'] ?? $user->postcode;

            $user->updated_by   = $data['updated_by'] ?? $user->updated_by;

            $user->save();


            // Get vmd_user_email from the post data
            $createdByEmail = $data['vmd_user_email'];
            $createdByName  = $data['vmd_user_name'];



            // Log the update action in user_audit_history
            DB::table('user_audit_history')->insert([
                'custno' => $data['id'] + 100000,
                'dteprfmd' => now(),
                'comments' => $data['vmd_audit_reason'],
                'clerk_id' => $data['vmd_user_name'],
                'created_by_email' => $data['vmd_user_email'],
                'created_by_ip_address' => $this->getRequestIpAddress($request),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($passwordWasChanged) {
                $this->createPersistedNotification([
                    'recipient_email' => $user->email,
                    'actor_email' => $data['vmd_user_email'],
                    'type' => 'warning',
                    'title' => 'Password changed',
                    'message' => "Your password was changed by {$data['vmd_user_name']}.",
                    'source' => 'user-management',
                    'dedupe_key' => 'staff-password-changed-' . intval($user->id) . '-' . now()->format('YmdHis'),
                    'metadata' => [
                        'user_id' => intval($user->id),
                        'identity_type' => 'staff',
                    ],
                ]);
            } elseif (strcasecmp($oldRoleName, (string) $user->role_name) !== 0) {
                $this->createPersistedNotification([
                    'recipient_email' => $user->email,
                    'actor_email' => $data['vmd_user_email'],
                    'type' => 'warning',
                    'title' => 'Role changed',
                    'message' => "Your role was changed from {$oldRoleName} to {$user->role_name} by {$data['vmd_user_name']}. Your permissions have been updated.",
                    'source' => 'user-management',
                    'dedupe_key' => 'staff-role-changed-' . intval($user->id) . '-' . strtolower($oldRoleName) . '-' . strtolower((string) $user->role_name) . '-' . now()->format('YmdHis'),
                    'metadata' => [
                        'user_id' => intval($user->id),
                        'identity_type' => 'staff',
                        'previousRole' => $oldRoleName,
                        'role' => $user->role_name,
                    ],
                ]);
            } else {
                $this->createPersistedNotification([
                    'recipient_email' => $user->email,
                    'actor_email' => $data['vmd_user_email'],
                    'type' => 'info',
                    'title' => 'Basic Info changed',
                    'message' => "Your Basic Info was changed by {$data['vmd_user_name']}.",
                    'source' => 'user-management',
                    'dedupe_key' => 'staff-basic-info-changed-' . intval($user->id) . '-' . now()->format('YmdHis'),
                    'metadata' => [
                        'user_id' => intval($user->id),
                        'identity_type' => 'staff',
                    ],
                ]);
            }

            $response['outcome'] = "SUCCESS";
            $response['message'] = "User updated successfully.";
            $response['updated_details'] = [
                "id"            => intval($user->id),
                "custno"        => intval($user->custno),
                "email"         => $user->email,
                "name"          => $user->name,
                "company_name"  => $user->company_name,
                "gender"        => $user->gender,
                "location"      => $user->location,
                "address_1"     => $user->address_1,
                "address_2"     => $user->address_2,
                "address_3"     => $user->address_3,
                "city"          => $user->city,
                "role_id"       => intval($user->role_id),
                "role_name"     => $user->role_name,
                "is_system_user" => (bool) $user->is_system_user,
                "is_game_user"   => (bool) $user->is_game_user,
                "postcode"      => $user->postcode,
                "phone_no"      => $user->phone_no,
                "profile_image" => $user->profile_image,
                "state"         => $user->state,
                "updated_at"    => $user->updated_at,
                "updated_by"    => $user->updated_by,
            ];
        } else {
            $response['outcome'] = false;
            $response['message'] = "User not found.";
            return response()->json($response, 404);
        }

        return response()->json($response, 200);
    }

    public function F0_VMD_update_game_user_basic_info(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer',
            'email' => 'required|email',
            'name' => 'required|string|max:255',
            'gender' => 'nullable|string|max:50',
            'location' => 'nullable|string|max:255',
            'phone_no' => 'nullable|string|max:50',
            'languages' => 'nullable|array',
            'languages.*' => 'string|max:100',
            'role_name' => 'nullable|string|max:50',
            'vmd_audit_reason' => 'nullable|string|max:255',
            'vmd_user_email' => 'required|email',
            'vmd_user_name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Validation failed.',
                'errors' => $validator->errors()->toArray(),
            ], 409);
        }

        $data = $validator->validated();
        if ($timeoutResponse = $this->userManagementTimeoutResponse($data['vmd_user_email'])) {
            return $timeoutResponse;
        }

        $gameUser = DB::table('game_users')
            ->leftJoin('game_intakes', 'game_users.intake_id', '=', 'game_intakes.id')
            ->where('game_users.id', $data['id'])
            ->select(
                'game_users.*',
                'game_intakes.code as intake_code',
                'game_intakes.name as intake_name',
                'game_intakes.active_week as intake_active_week'
            )
            ->first();

        if (! $gameUser) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Game user not found.',
            ], 404);
        }

        if ($geoLockResponse = $this->geoLockUserEditResponse(
            $request,
            $gameUser->intake_id ? intval($gameUser->intake_id) : null,
            'F0_VMD_update_game_user_basic_info()'
        )) {
            return $geoLockResponse;
        }

        $isSelfUpdate = strcasecmp($gameUser->email, $data['email']) === 0
            && strcasecmp($gameUser->email, $data['vmd_user_email']) === 0;

        if (strcasecmp($gameUser->email, $data['email']) !== 0 || (! $isSelfUpdate && ! $this->canManageGameUsers($data['vmd_user_email']))) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Permission Denied: You can only update your own student profile unless you are Admin, Protector, Trainer, or Spy.',
            ], 403);
        }

        if (isset($data['role_name']) && strcasecmp((string) $data['role_name'], 'Trainer') === 0) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Students cannot be assigned the Trainer role.',
            ], 422);
        }

        if ($this->nonAdminStaffAssigningAdmin($data['vmd_user_email'], null, $data['role_name'] ?? null)) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Only Staff Admins can assign the Admin role.',
            ], 403);
        }

        $oldRole = (string) $gameUser->game_role;
        $newRole = (string) ($data['role_name'] ?? $gameUser->game_role);

        DB::table('game_users')->where('id', $data['id'])->update([
            'display_name' => $data['name'],
            'gender' => $data['gender'] ?? null,
            'location' => $data['location'] ?? null,
            'phone_no' => $data['phone_no'] ?? null,
            'languages' => json_encode($data['languages'] ?? []),
            'game_role' => $newRole,
            'updated_by' => $data['vmd_user_email'],
            'updated_at' => now(),
        ]);

        $updatedGameUser = DB::table('game_users')
            ->leftJoin('game_intakes', 'game_users.intake_id', '=', 'game_intakes.id')
            ->where('game_users.id', $data['id'])
            ->select(
                'game_users.*',
                'game_intakes.code as intake_code',
                'game_intakes.name as intake_name',
                'game_intakes.active_week as intake_active_week'
            )
            ->first();

        $languages = [];
        if (! empty($updatedGameUser->languages)) {
            $decodedLanguages = json_decode($updatedGameUser->languages, true);
            $languages = is_array($decodedLanguages) ? $decodedLanguages : [];
        }

        $displayName = $updatedGameUser->display_name
            ?: trim(($updatedGameUser->preferred_name ?: $updatedGameUser->first_name) . ' ' . $updatedGameUser->surname);
        $auditReason = trim((string) ($data['vmd_audit_reason'] ?? ''));
        $auditComment = $auditReason !== ''
            ? $auditReason
            : "Student profile updated: {$displayName} ({$updatedGameUser->email}) in {$updatedGameUser->intake_name}.";

        DB::table('user_audit_history')->insert([
            'custno' => 900000 + intval($updatedGameUser->id),
            'dteprfmd' => now(),
            'comments' => $auditComment,
            'clerk_id' => $data['vmd_user_name'],
            'created_by_email' => $data['vmd_user_email'],
            'created_by_ip_address' => $this->getRequestIpAddress($request),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createPersistedNotification([
            'recipient_email' => $updatedGameUser->email,
            'actor_email' => $data['vmd_user_email'],
            'type' => 'info',
            'title' => 'Basic Info changed',
            'message' => "Your Basic Info was changed by {$data['vmd_user_name']}.",
            'source' => 'user-management',
            'dedupe_key' => 'student-basic-info-changed-' . intval($updatedGameUser->id) . '-' . now()->format('YmdHis'),
            'metadata' => [
                'game_user_id' => intval($updatedGameUser->id),
                'identity_type' => 'student',
                'intake_code' => $updatedGameUser->intake_code,
            ],
        ]);

        if (strcasecmp($oldRole, (string) $updatedGameUser->game_role) !== 0) {
            $this->createPersistedNotification([
                'recipient_email' => $updatedGameUser->email,
                'actor_email' => $data['vmd_user_email'],
                'type' => 'warning',
                'title' => 'Role changed',
                'message' => "Your role was changed from {$oldRole} to {$updatedGameUser->game_role} by {$data['vmd_user_name']}. Your permissions have been updated.",
                'source' => 'user-management',
                'dedupe_key' => 'student-role-changed-' . intval($updatedGameUser->id) . '-' . strtolower($oldRole) . '-' . strtolower((string) $updatedGameUser->game_role) . '-' . now()->format('YmdHis'),
                'metadata' => [
                    'game_user_id' => intval($updatedGameUser->id),
                    'previousRole' => $oldRole,
                    'role' => $updatedGameUser->game_role,
                    'intake_code' => $updatedGameUser->intake_code,
                ],
            ]);
        }

        return response()->json([
            'outcome' => 'SUCCESS',
            'message' => 'Student basic info updated successfully.',
            'updated_details' => [
                'id' => intval($updatedGameUser->id),
                'custno' => 900000 + intval($updatedGameUser->id),
                'profile_image' => $updatedGameUser->profile_image,
                'role_id' => null,
                'role_name' => $updatedGameUser->game_role,
                'status' => $updatedGameUser->game_status,
                'is_system_user' => false,
                'is_game_user' => true,
                'identity_type' => 'student',
                'game_user_id' => intval($updatedGameUser->id),
                'game_intake_id' => intval($updatedGameUser->intake_id),
                'game_intake_code' => $updatedGameUser->intake_code,
                'game_intake_name' => $updatedGameUser->intake_name,
                'game_active_week' => $updatedGameUser->intake_active_week,
                'email' => $updatedGameUser->email,
                'name' => $displayName,
                'company_name' => $updatedGameUser->intake_name,
                'gender' => $updatedGameUser->gender,
                'location' => $updatedGameUser->location,
                'languages' => $languages,
                'address_1' => null,
                'address_2' => null,
                'address_3' => null,
                'city' => $updatedGameUser->city,
                'state' => $updatedGameUser->state,
                'postcode' => $updatedGameUser->postcode,
                'phone_no' => $updatedGameUser->phone_no,
                'updated_at' => $updatedGameUser->updated_at,
                'updated_by' => $updatedGameUser->updated_by,
            ],
        ], 200);
    }

    public function F0_VMD_update_game_user_password(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer',
            'password' => 'required|string|min:8|max:255',
            'password_confirmation' => 'required|string|same:password',
            'vmd_user_email' => 'required|email',
            'vmd_user_name' => 'required|string|max:255',
            'vmd_audit_reason' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Validation failed.',
                'errors' => $validator->errors()->toArray(),
            ], 409);
        }

        $data = $validator->validated();
        if ($timeoutResponse = $this->userManagementTimeoutResponse($data['vmd_user_email'])) {
            return $timeoutResponse;
        }

        $gameUser = DB::table('game_users')
            ->leftJoin('game_intakes', 'game_users.intake_id', '=', 'game_intakes.id')
            ->where('game_users.id', $data['id'])
            ->select(
                'game_users.*',
                'game_intakes.code as intake_code',
                'game_intakes.name as intake_name'
            )
            ->first();

        if (! $gameUser) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Game user not found.',
            ], 404);
        }

        if ($geoLockResponse = $this->geoLockUserEditResponse(
            $request,
            $gameUser->intake_id ? intval($gameUser->intake_id) : null,
            'F0_VMD_update_game_user_password()'
        )) {
            return $geoLockResponse;
        }

        $isSelfPasswordChange = strcasecmp($gameUser->email, $data['vmd_user_email']) === 0;

        if (! $isSelfPasswordChange && ! $this->canManageGameUsers($data['vmd_user_email'])) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Permission Denied: You can only change your own student password unless you are Admin, Protector, Trainer, or Spy.',
            ], 403);
        }

        DB::table('game_users')->where('id', $data['id'])->update([
            'password' => bcrypt($data['password']),
            'must_change_password' => false,
            'updated_by' => $data['vmd_user_email'],
            'updated_at' => now(),
        ]);

        DB::table('user_audit_history')->insert([
            'custno' => 900000 + intval($gameUser->id),
            'dteprfmd' => now(),
            'comments' => $data['vmd_audit_reason'] ?? 'User password changed',
            'clerk_id' => $data['vmd_user_name'],
            'created_by_email' => $data['vmd_user_email'],
            'created_by_ip_address' => $this->getRequestIpAddress($request),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createPersistedNotification([
            'recipient_email' => $gameUser->email,
            'actor_email' => $data['vmd_user_email'],
            'type' => 'warning',
            'title' => 'Password changed',
            'message' => "Your password was changed by {$data['vmd_user_name']}.",
            'source' => 'user-management',
            'dedupe_key' => 'student-password-changed-' . intval($gameUser->id) . '-' . now()->format('YmdHis'),
            'metadata' => [
                'game_user_id' => intval($gameUser->id),
                'identity_type' => 'student',
                'intake_code' => $gameUser->intake_code,
            ],
        ]);

        return response()->json([
            'outcome' => 'SUCCESS',
            'message' => 'Student password updated successfully.',
            'must_change_password' => false,
        ], 200);
    }

    private function updateGameUserStatus(Request $request, string $status, string $message)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer',
            'vmd_user_email' => 'required|email',
            'vmd_user_name' => 'required|string|max:255',
            'vmd_audit_reason' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Validation failed.',
                'errors' => $validator->errors()->toArray(),
            ], 409);
        }

        $data = $validator->validated();
        if ($timeoutResponse = $this->userManagementTimeoutResponse($data['vmd_user_email'])) {
            return $timeoutResponse;
        }

        if (! $this->canManageGameUsers($data['vmd_user_email'])) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Permission Denied: Admin, Protector, Trainer, or Spy access required.',
            ], 403);
        }

        $gameUser = DB::table('game_users')
            ->leftJoin('game_intakes', 'game_users.intake_id', '=', 'game_intakes.id')
            ->where('game_users.id', $data['id'])
            ->select(
                'game_users.*',
                'game_intakes.code as intake_code',
                'game_intakes.name as intake_name'
            )
            ->first();

        if (! $gameUser) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Game user not found.',
            ], 404);
        }

        if (
            $status === 'ACTIVE'
            && strcasecmp((string) $gameUser->game_status, 'DELETED') === 0
            && ! $this->canRestoreDeletedUsers($data['vmd_user_email'])
        ) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Permission Denied: Staff Admin or Staff Protector access required to restore deleted users.',
            ], 403);
        }

        if (
            in_array($status, ['BANNED', 'DELETED'], true)
            && $this->isGameUserAdminActor($data['vmd_user_email'])
            && strcasecmp((string) $gameUser->game_role, 'Admin') === 0
        ) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Permission Denied: Student Admin users cannot ban or delete Admin users.',
            ], 403);
        }

        $previousStatus = strtoupper((string) $gameUser->game_status);
        $targetWasActive = $previousStatus === 'ACTIVE';

        DB::table('game_users')->where('id', $data['id'])->update([
            'game_status' => $status,
            'updated_by' => $data['vmd_user_email'],
            'updated_at' => now(),
        ]);

        $auditAction = match ($status) {
            'BANNED' => 'Student was successfully BANNED',
            'ACTIVE' => 'Student was successfully UNBANNED',
            'DELETED' => 'Student was successfully DELETED',
            default => "Student status changed to " . strtoupper($status),
        };

        DB::table('user_audit_history')->insert([
            'custno' => 900000 + intval($gameUser->id),
            'dteprfmd' => now(),
            'comments' => $auditAction,
            'clerk_id' => $data['vmd_user_name'],
            'created_by_email' => $data['vmd_user_email'],
            'created_by_ip_address' => $this->getRequestIpAddress($request),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($status === 'ACTIVE') {
            $displayName = $gameUser->display_name
                ?: trim(($gameUser->preferred_name ?: $gameUser->first_name) . ' ' . $gameUser->surname);
            $wasDeleted = $previousStatus === 'DELETED';

            $this->createPersistedNotification([
                'recipient_email' => $gameUser->email,
                'actor_email' => $data['vmd_user_email'],
                'type' => 'info',
                'title' => $wasDeleted ? 'Account undeleted' : 'You have been unbanned',
                'message' => $wasDeleted
                    ? "{$displayName}, your account was undeleted by {$data['vmd_user_name']}."
                    : "{$displayName}, your account has been unbanned by {$data['vmd_user_name']}.",
                'source' => 'user-management',
                'dedupe_key' => 'student-' . ($wasDeleted ? 'undeleted' : 'unbanned') . '-' . intval($gameUser->id) . '-' . now()->format('YmdHis'),
                'metadata' => [
                    'game_user_id' => intval($gameUser->id),
                    'identity_type' => 'student',
                    'previous_status' => $previousStatus,
                    'game_status' => $status,
                    'intake_code' => $gameUser->intake_code,
                ],
            ]);
        } elseif ($status === 'DELETED') {
            $displayName = $gameUser->display_name
                ?: trim(($gameUser->preferred_name ?: $gameUser->first_name) . ' ' . $gameUser->surname);

            $this->createPersistedNotification([
                'recipient_email' => $gameUser->email,
                'actor_email' => $data['vmd_user_email'],
                'type' => 'error',
                'title' => 'Account deleted',
                'message' => "{$displayName}, your account was deleted by {$data['vmd_user_name']}.",
                'source' => 'user-management',
                'dedupe_key' => 'student-deleted-' . intval($gameUser->id) . '-' . now()->format('YmdHis'),
                'metadata' => [
                    'game_user_id' => intval($gameUser->id),
                    'identity_type' => 'student',
                    'previous_status' => $previousStatus,
                    'game_status' => $status,
                    'intake_code' => $gameUser->intake_code,
                ],
            ]);
        }

        $actorActionLockedUntil = null;
        if (in_array($status, ['BANNED', 'DELETED'], true)) {
            $actorActionLockedUntil = $this->applyActionCooldownIfNeeded(
                $data['vmd_user_email'],
                $gameUser,
                $targetWasActive,
                $status === 'BANNED' ? 'Banned' : 'Deleted'
            );
        }

        return response()->json([
            'outcome' => 'SUCCESS',
            'message' => $message,
            'game_user_id' => intval($data['id']),
            'game_status' => $status,
            'actor_action_locked_until' => $actorActionLockedUntil ? $actorActionLockedUntil->toDateTimeString() : null,
        ], 200);
    }

    public function F0_VMD_ban_game_user(Request $request)
    {
        return $this->updateGameUserStatus($request, 'BANNED', 'Game user banned successfully.');
    }

    public function F0_VMD_unban_game_user(Request $request)
    {
        return $this->updateGameUserStatus($request, 'ACTIVE', 'Game user unbanned successfully.');
    }

    public function F0_VMD_delete_game_user(Request $request)
    {
        return $this->updateGameUserStatus($request, 'DELETED', 'Game user deleted successfully.');
    }

    public function F0_VMD_permanently_delete_game_user(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer',
            'vmd_user_email' => 'required|email',
            'vmd_user_name' => 'required|string|max:255',
            'vmd_audit_reason' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Validation failed.',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $data = $validator->validated();

        if (! $this->canRestoreDeletedUsers($data['vmd_user_email'])) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Permission Denied: Staff Admin or Staff Protector access required to permanently delete users.',
            ], 403);
        }

        $gameUser = DB::table('game_users')->where('id', $data['id'])->first();

        if (! $gameUser) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Game user not found.',
            ], 404);
        }

        if (strcasecmp((string) $gameUser->game_status, 'DELETED') !== 0) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Only users already marked DELETED can be permanently deleted.',
            ], 409);
        }

        DB::table('user_audit_history')->insert([
            'custno' => 900000 + intval($gameUser->id),
            'dteprfmd' => now(),
            'comments' => $this->permanentDeleteAuditComment(
                $data['vmd_audit_reason'] ?: 'Student permanently deleted',
                $gameUser->display_name ?: trim(($gameUser->preferred_name ?: $gameUser->first_name) . ' ' . $gameUser->surname),
                $gameUser->email
            ),
            'clerk_id' => $data['vmd_user_name'],
            'created_by_email' => $data['vmd_user_email'],
            'created_by_ip_address' => $this->getRequestIpAddress($request),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('game_users')->where('id', $data['id'])->delete();

        return response()->json([
            'outcome' => 'SUCCESS',
            'message' => 'Game user permanently deleted successfully.',
            'game_user_id' => intval($data['id']),
        ], 200);
    }











    public function F0_VMD_get_dashboard_settings(Request $request)
    {
        $request->validate([
            'vmd_user_email' => 'required|email',
        ]);

        if (!$this->canAccessGlobalManagement($request->input('vmd_user_email'))) {
            return response()->json([
                'outcome' => 'ERROR: Staff Admin or Trainer access required.',
            ], 403);
        }

        return response()->json([
            'outcome' => 'SUCCESS: Dashboard settings loaded.',
            'settings' => $this->dashboardSettingRows(),
        ]);
    }









    public function F0_VMD_update_dashboard_settings(Request $request)
    {
        $request->validate([
            'vmd_user_email' => 'required|email',
            'settings' => 'required|array',
            'settings.login_2fa_enabled' => 'nullable|boolean',
            'settings.login_2fa_send_to_account' => 'nullable|boolean',
            'settings.login_2fa_send_to_master' => 'nullable|boolean',
            'settings.login_2fa_master_email' => 'nullable|email',
            'settings.game_delete_cooldown_enabled' => 'nullable|boolean',
            'settings.game_delete_cooldown_minutes' => 'nullable|integer|min:1|max:1440',
        ]);

        $actorEmail = $request->input('vmd_user_email');

        if (!$this->canAccessGlobalManagement($actorEmail)) {
            return response()->json([
                'outcome' => 'ERROR: Staff Admin or Trainer access required.',
            ], 403);
        }

        $settings = $request->input('settings');
        $isAdmin = $this->isAdminEmail($actorEmail);
        $updates = [];

        if ($isAdmin) {
            $twoFactorEnabled = (bool) ($settings['login_2fa_enabled'] ?? false);
            $sendToAccount = (bool) ($settings['login_2fa_send_to_account'] ?? false);
            $sendToMaster = (bool) ($settings['login_2fa_send_to_master'] ?? false);
            $masterEmail = $settings['login_2fa_master_email'] ?? '';

            if ($twoFactorEnabled && !$sendToAccount && !$sendToMaster) {
                return response()->json([
                    'outcome' => 'ERROR: Invalid dashboard settings.',
                    'message' => 'When 2FA is enabled, at least one 2FA email destination must be enabled.',
                ], 422);
            }

            if ($twoFactorEnabled && $sendToMaster && !$masterEmail) {
                return response()->json([
                    'outcome' => 'ERROR: Invalid dashboard settings.',
                    'message' => 'A valid master email account is required when master 2FA email copies are enabled.',
                ], 422);
            }

            $updates = [
                'login_2fa_enabled' => $twoFactorEnabled ? '1' : '0',
                'login_2fa_send_to_account' => $sendToAccount ? '1' : '0',
                'login_2fa_send_to_master' => $sendToMaster ? '1' : '0',
                'login_2fa_master_email' => $masterEmail,
            ];
        }

        if (array_key_exists('game_delete_cooldown_enabled', $settings)) {
            $updates['game_delete_cooldown_enabled'] = (bool) $settings['game_delete_cooldown_enabled'] ? '1' : '0';
        }

        if (array_key_exists('game_delete_cooldown_minutes', $settings)) {
            $updates['game_delete_cooldown_minutes'] = (string) intval($settings['game_delete_cooldown_minutes']);
        }

        foreach ($updates as $key => $value) {
            DB::table('dashboard_settings')
                ->where('key', $key)
                ->update([
                    'value' => $value,
                    'updated_at' => now(),
                ]);
        }

        return response()->json([
            'outcome' => 'SUCCESS: Dashboard settings updated.',
            'settings' => $this->dashboardSettingRows(),
        ]);
    }

    public function F0_VMD_get_intake_game_settings(Request $request)
    {
        $request->validate([
            'vmd_user_email' => 'required|email',
            'game_intake_code' => 'nullable|string|exists:game_intakes,code',
        ]);

        if (! Schema::hasTable('game_intake_settings')) {
            return response()->json([
                'outcome' => 'ERROR: Intake game settings table is missing.',
                'message' => 'Game intake settings table is missing. Run migrations.',
            ], 500);
        }

        $email = $request->input('vmd_user_email');
        $requestedIntakeCode = $request->input('game_intake_code') ? trim($request->input('game_intake_code')) : null;

        $gameUser = GameUser::where('email', $email)->first();
        if ($gameUser) {
            $intakeId = (int) $gameUser->intake_id;
            $freshIntake = DB::table('game_intakes')->where('id', $intakeId)->first();
            if ($requestedIntakeCode && $freshIntake && $requestedIntakeCode !== $freshIntake->code) {
                return response()->json([
                    'outcome' => 'ERROR: Class Intake not available.',
                    'message' => 'You do not have access to that Class Intake.',
                ], 403);
            }

            $this->ensureIntakeGameSettings($intakeId);

            return response()->json([
                'outcome' => 'SUCCESS: Intake game settings loaded.',
                'intakes' => [[
                    'id' => $intakeId,
                    'code' => $freshIntake->code,
                    'name' => $freshIntake->name,
                    'status' => $freshIntake->status,
                    'activeWeek' => $freshIntake->active_week,
                ]],
                'selected_intake' => [
                    'id' => $intakeId,
                    'code' => $freshIntake->code,
                    'name' => $freshIntake->name,
                    'status' => $freshIntake->status,
                    'activeWeek' => $freshIntake->active_week,
                ],
                'settings' => $this->intakeGameSettingRows($intakeId),
            ]);
        }

        if (!$this->canAccessGlobalManagement($email)) {
            return response()->json([
                'outcome' => 'ERROR: Staff Admin or Trainer access required.',
            ], 403);
        }

        $user = User::where('email', $email)->first();
        if (! $user) {
            return response()->json([
                'outcome' => 'ERROR: Staff user not found.',
            ], 404);
        }

        $intakes = $this->staffVisibleIntakes($user);
        if ($intakes->isEmpty()) {
            return response()->json([
                'outcome' => 'SUCCESS: No Class Intakes available.',
                'intakes' => [],
                'selected_intake' => null,
                'settings' => [],
            ]);
        }

        $selectedIntake = $requestedIntakeCode
            ? $intakes->firstWhere('code', $requestedIntakeCode)
            : $intakes->first();

        if (! $selectedIntake) {
            return response()->json([
                'outcome' => 'ERROR: Class Intake not available.',
                'message' => 'You do not have access to that Class Intake.',
            ], 403);
        }

        $intakeId = (int) $selectedIntake['id'];
        $this->ensureIntakeGameSettings($intakeId);
        $freshIntake = DB::table('game_intakes')->where('id', $intakeId)->first();

        return response()->json([
            'outcome' => 'SUCCESS: Intake game settings loaded.',
            'intakes' => $intakes,
            'selected_intake' => [
                'id' => $intakeId,
                'code' => $freshIntake->code,
                'name' => $freshIntake->name,
                'status' => $freshIntake->status,
                'activeWeek' => $freshIntake->active_week,
            ],
            'settings' => $this->intakeGameSettingRows($intakeId),
        ]);
    }

    public function F0_VMD_save_intake_game_settings(Request $request)
    {
        $request->validate([
            'vmd_user_email' => 'required|email',
            'game_intake_code' => 'required|string|exists:game_intakes,code',
            'active_week' => 'required|string|in:week_1,week_2,week_3,week_4,week_5,week_6',
            'settings' => 'required|array',
        ]);

        if (! Schema::hasTable('game_intake_settings')) {
            return response()->json([
                'outcome' => 'ERROR: Intake game settings table is missing.',
                'message' => 'Game intake settings table is missing. Run migrations.',
            ], 500);
        }

        $email = $request->input('vmd_user_email');
        $gameIntakeCode = trim($request->input('game_intake_code'));
        if (!$this->canAccessGlobalManagement($email)) {
            return response()->json([
                'outcome' => 'ERROR: Staff Admin or Trainer access required.',
            ], 403);
        }

        $user = User::where('email', $email)->first();
        $intake = DB::table('game_intakes')->where('code', $gameIntakeCode)->first();
        $intakeId = (int) $intake->id;

        if (! $user || ! $this->staffCanAccessIntake($user, null, $gameIntakeCode)) {
            return response()->json([
                'outcome' => 'ERROR: Class Intake not available.',
                'message' => 'You do not have access to that Class Intake.',
            ], 403);
        }

        $definitions = $this->intakeGameSettingDefinitions();
        $settings = $request->input('settings', []);

        if (
            array_key_exists('game_delete_cooldown_minutes', $settings) &&
            (! is_numeric($settings['game_delete_cooldown_minutes']) ||
                intval($settings['game_delete_cooldown_minutes']) < 1 ||
                intval($settings['game_delete_cooldown_minutes']) > 1440)
        ) {
            return response()->json([
                'outcome' => 'ERROR: Invalid intake game settings.',
                'message' => 'Delete timeout minutes must be between 1 and 1440.',
            ], 422);
        }

        DB::transaction(function () use ($intakeId, $request, $definitions, $settings) {
            DB::table('game_intakes')
                ->where('id', $intakeId)
                ->update([
                    'active_week' => $request->input('active_week'),
                    'updated_at' => now(),
                ]);

            $this->ensureIntakeGameSettings($intakeId);

            foreach ($settings as $key => $value) {
                if (! array_key_exists($key, $definitions)) {
                    continue;
                }

                $definition = $definitions[$key];
                DB::table('game_intake_settings')
                    ->where('game_intake_id', $intakeId)
                    ->where('key', $key)
                    ->update([
                        'value' => $this->intakeSettingStorageValue($value, $definition['type']),
                        'updated_at' => now(),
                    ]);
            }
        });

        $freshIntake = DB::table('game_intakes')->where('id', $intakeId)->first();

        return response()->json([
            'outcome' => 'SUCCESS: Intake game settings saved.',
            'selected_intake' => [
                'id' => $intakeId,
                'code' => $freshIntake->code,
                'name' => $freshIntake->name,
                'status' => $freshIntake->status,
                'activeWeek' => $freshIntake->active_week,
            ],
            'settings' => $this->intakeGameSettingRows($intakeId),
        ]);
    }

    public function F0_VMD_get_user_table_baselines(Request $request)
    {
        $request->validate([
            'vmd_user_email' => 'required|email',
        ]);

        if (!$this->isAdminEmail($request->input('vmd_user_email'))) {
            return response()->json([
                'outcome' => 'ERROR: Admin access required.',
            ], 403);
        }

        $baselines = DB::table('user_table_baselines')
            ->leftJoin('users', 'users.id', '=', 'user_table_baselines.created_by_user_id')
            ->select(
                'user_table_baselines.*',
                'users.name as created_by_name',
                'users.email as created_by_email'
            )
            ->orderBy('user_table_baselines.created_at', 'DESC')
            ->get()
            ->map(function ($baseline) {
                $rowCount = DB::table('user_table_baseline_rows')
                    ->where('baseline_id', $baseline->id)
                    ->count();

                return [
                    'id' => $baseline->id,
                    'name' => $baseline->name,
                    'description' => $baseline->description,
                    'is_active' => (bool) $baseline->is_active,
                    'created_by_user_id' => $baseline->created_by_user_id,
                    'created_by_name' => $baseline->created_by_name,
                    'created_by_email' => $baseline->created_by_email,
                    'row_count' => $rowCount,
                    'created_at' => $baseline->created_at,
                    'updated_at' => $baseline->updated_at,
                ];
            });

        return response()->json([
            'outcome' => 'SUCCESS: User table baselines loaded.',
            'data' => $baselines,
        ], 200);
    }

    private function userTableBaselineRows()
    {
        return DB::table('user_table_baselines')
            ->leftJoin('users', 'users.id', '=', 'user_table_baselines.created_by_user_id')
            ->select(
                'user_table_baselines.*',
                'users.name as created_by_name',
                'users.email as created_by_email'
            )
            ->orderBy('user_table_baselines.created_at', 'DESC')
            ->get()
            ->map(function ($baseline) {
                return [
                    'id' => $baseline->id,
                    'name' => $baseline->name,
                    'description' => $baseline->description,
                    'is_active' => (bool) $baseline->is_active,
                    'created_by_user_id' => $baseline->created_by_user_id,
                    'created_by_name' => $baseline->created_by_name,
                    'created_by_email' => $baseline->created_by_email,
                    'row_count' => DB::table('user_table_baseline_rows')->where('baseline_id', $baseline->id)->count(),
                    'created_at' => $baseline->created_at,
                    'updated_at' => $baseline->updated_at,
                    'baseline_type' => 'users',
                ];
            });
    }

    private function intakeGameSettingDefinitions(): array
    {
        return [
            'security_manual_login_requires_2fa' => ['type' => 'boolean', 'group' => 'game-controls', 'label' => 'Manual login requires 2FA', 'sort' => 10],
            'security_block_banned_login' => ['type' => 'boolean', 'group' => 'game-controls', 'label' => 'Banned players cannot log in', 'sort' => 20],
            'security_geo_lock_user_edits' => ['type' => 'boolean', 'group' => 'game-controls', 'label' => 'User edits must originate from Australia', 'sort' => 40],
            'game_block_student_add_users' => ['type' => 'boolean', 'group' => 'game-vulnerabilities', 'label' => 'Students can no longer Add Users', 'sort' => 90],
            'game_restrict_student_role_selection' => ['type' => 'boolean', 'group' => 'game-vulnerabilities', 'label' => 'Students can no longer choose any role for new users.', 'sort' => 100],
            'game_delete_cooldown_enabled' => ['type' => 'boolean', 'group' => 'elimination-recovery', 'label' => 'Students receive a lockdown when they Delete or Ban someone.', 'sort' => 120],
            'game_delete_cooldown_minutes' => ['type' => 'integer', 'group' => 'elimination-recovery', 'label' => 'Delete timeout minutes', 'sort' => 130],
            'game_allow_undelete' => ['type' => 'boolean', 'group' => 'elimination-recovery', 'label' => 'Deleted players can be restored by defenders', 'sort' => 140],
            'game_protector_spy_controls' => ['type' => 'boolean', 'group' => 'roles-spies', 'label' => 'Protector spy controls are enabled', 'sort' => 150],
            'game_spy_audit_impersonation' => ['type' => 'boolean', 'group' => 'roles-spies', 'label' => 'Spies can appear as other users in audit screens', 'sort' => 160],
            'game_account_drill_down_enabled' => ['type' => 'boolean', 'group' => 'roles-spies', 'label' => 'Admins can trace fake-account ownership', 'sort' => 165],
            'game_last_man_standing_enabled' => ['type' => 'boolean', 'group' => 'elimination-recovery', 'label' => 'Winner is the last active eligible player', 'sort' => 170],
            'game_auto_detect_winner' => ['type' => 'boolean', 'group' => 'elimination-recovery', 'label' => 'Automatically detect a winner when one player remains', 'sort' => 180],
            'game_baseline_reset_enabled' => ['type' => 'boolean', 'group' => 'elimination-recovery', 'label' => 'Class baseline reset is enabled', 'sort' => 190],
        ];
    }

    private function intakeWeekDefaults(string $weekId): array
    {
        $weekOne = [
            'security_manual_login_requires_2fa' => false,
            'security_block_banned_login' => true,
            'security_geo_lock_user_edits' => false,
            'game_block_student_add_users' => false,
            'game_restrict_student_role_selection' => false,
            'game_delete_cooldown_enabled' => false,
            'game_allow_undelete' => false,
            'game_protector_spy_controls' => false,
            'game_spy_audit_impersonation' => false,
            'game_account_drill_down_enabled' => false,
            'game_last_man_standing_enabled' => true,
            'game_auto_detect_winner' => false,
            'game_baseline_reset_enabled' => true,
        ];

        $weeks = [
            'week_1' => [],
            'week_2' => [
                'security_block_banned_login' => true,
                'game_delete_cooldown_enabled' => true,
                'game_allow_undelete' => true,
            ],
            'week_3' => [
                'security_block_banned_login' => true,
                'game_delete_cooldown_enabled' => true,
                'game_allow_undelete' => true,
                'game_protector_spy_controls' => true,
                'game_auto_detect_winner' => true,
            ],
            'week_4' => [
                'security_block_banned_login' => true,
                'security_geo_lock_user_edits' => true,
                'game_delete_cooldown_enabled' => true,
                'game_allow_undelete' => true,
                'game_protector_spy_controls' => true,
                'game_account_drill_down_enabled' => true,
                'game_auto_detect_winner' => true,
            ],
            'week_5' => [
                'security_manual_login_requires_2fa' => true,
                'security_block_banned_login' => true,
                'security_geo_lock_user_edits' => true,
                'game_block_student_add_users' => true,
                'game_delete_cooldown_enabled' => true,
                'game_allow_undelete' => true,
                'game_protector_spy_controls' => true,
                'game_spy_audit_impersonation' => true,
                'game_account_drill_down_enabled' => true,
                'game_auto_detect_winner' => true,
            ],
            'week_6' => [
                'security_manual_login_requires_2fa' => true,
                'security_block_banned_login' => true,
                'security_geo_lock_user_edits' => true,
                'game_block_student_add_users' => true,
                'game_restrict_student_role_selection' => true,
                'game_delete_cooldown_enabled' => true,
                'game_allow_undelete' => true,
                'game_protector_spy_controls' => true,
                'game_spy_audit_impersonation' => true,
                'game_account_drill_down_enabled' => true,
                'game_auto_detect_winner' => true,
            ],
        ];

        return array_merge($weekOne, $weeks[$weekId] ?? []);
    }

    private function intakeSettingResponseValue($value, string $type)
    {
        if ($type === 'boolean') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        if ($type === 'integer') {
            return is_numeric($value) ? intval($value) : 0;
        }

        return $value;
    }

    private function intakeSettingStorageValue($value, string $type): string
    {
        if ($type === 'boolean') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        if ($type === 'integer') {
            return (string) intval($value);
        }

        return (string) $value;
    }

    private function ensureIntakeGameSettings(int $intakeId): void
    {
        if (! Schema::hasTable('game_intake_settings')) {
            return;
        }

        $intake = DB::table('game_intakes')->where('id', $intakeId)->first(['active_week']);
        $defaults = $this->intakeWeekDefaults($intake?->active_week ?: 'week_1');
        $now = now();

        foreach ($this->intakeGameSettingDefinitions() as $key => $definition) {
            $existing = DB::table('game_intake_settings')
                ->where('game_intake_id', $intakeId)
                ->where('key', $key)
                ->exists();

            if ($existing) {
                DB::table('game_intake_settings')
                    ->where('game_intake_id', $intakeId)
                    ->where('key', $key)
                    ->update([
                        'type' => $definition['type'],
                        'group' => $definition['group'],
                        'label' => $definition['label'],
                        'sort_order' => $definition['sort'],
                        'updated_at' => $now,
                    ]);

                continue;
            }

            DB::table('game_intake_settings')->insert([
                    'value' => $this->intakeSettingStorageValue(
                        $defaults[$key] ?? ($definition['type'] === 'integer' ? 5 : false),
                        $definition['type']
                    ),
                    'game_intake_id' => $intakeId,
                    'key' => $key,
                    'type' => $definition['type'],
                    'group' => $definition['group'],
                    'label' => $definition['label'],
                    'sort_order' => $definition['sort'],
                    'updated_at' => $now,
                    'created_at' => $now,
            ]);
        }
    }

    private function intakeGameSettingRows(int $intakeId)
    {
        $activeKeys = array_keys($this->intakeGameSettingDefinitions());

        return DB::table('game_intake_settings')
            ->where('game_intake_id', $intakeId)
            ->whereIn('key', $activeKeys)
            ->orderBy('group')
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get()
            ->map(function ($setting) {
                return [
                    'id' => $setting->id,
                    'game_intake_id' => (int) $setting->game_intake_id,
                    'key' => $setting->key,
                    'value' => $this->intakeSettingResponseValue($setting->value, $setting->type),
                    'type' => $setting->type,
                    'group' => $setting->group,
                    'label' => $setting->label,
                    'description' => $setting->description,
                    'sort_order' => (int) $setting->sort_order,
                ];
            })
            ->values();
    }

    private function gameBaselineRows(?int $intakeId = null)
    {
        $query = DB::table('game_baselines')
            ->leftJoin('game_intakes', 'game_intakes.id', '=', 'game_baselines.intake_id')
            ->leftJoin('users', 'users.id', '=', 'game_baselines.created_by_user_id')
            ->select(
                'game_baselines.*',
                'game_intakes.code as intake_code',
                'game_intakes.name as intake_name',
                'users.name as created_by_name',
                'users.email as created_by_email'
            );

        if ($intakeId) {
            $query->where('game_baselines.intake_id', $intakeId);
        }

        return $query
            ->orderBy('game_baselines.created_at', 'DESC')
            ->get()
            ->map(function ($baseline) {
                return [
                    'id' => $baseline->id,
                    'name' => $baseline->name,
                    'description' => $baseline->description,
                    'is_active' => (bool) $baseline->is_active,
                    'intake_id' => $baseline->intake_id,
                    'intake_code' => $baseline->intake_code,
                    'intake_name' => $baseline->intake_name,
                    'created_by_user_id' => $baseline->created_by_user_id,
                    'created_by_name' => $baseline->created_by_name,
                    'created_by_email' => $baseline->created_by_email,
                    'row_count' => DB::table('game_baseline_users')->where('baseline_id', $baseline->id)->count(),
                    'created_at' => $baseline->created_at,
                    'updated_at' => $baseline->updated_at,
                    'baseline_type' => 'students',
                ];
            });
    }

    private function baselineIntakeRows()
    {
        return DB::table('game_intakes')
            ->select('id', 'code', 'name', 'status', 'active_week')
            ->orderByRaw("FIELD(status, 'active', 'planned')")
            ->orderBy('code')
            ->get()
            ->map(fn ($intake) => [
                'id' => (int) $intake->id,
                'code' => $intake->code,
                'name' => $intake->name,
                'status' => $intake->status,
                'active_week' => $intake->active_week,
            ]);
    }

    public function F0_VMD_get_baseline_management_data(Request $request)
    {
        $validated = $request->validate([
            'vmd_user_email' => 'required|email',
            'game_intake_code' => 'nullable|string|exists:game_intakes,code',
        ]);

        if (!$this->isAdminEmail($validated['vmd_user_email'])) {
            return response()->json([
                'outcome' => 'ERROR: Admin access required.',
            ], 403);
        }

        $intakeId = null;
        if (! empty($validated['game_intake_code'])) {
            $intakeId = (int) DB::table('game_intakes')
                ->where('code', $validated['game_intake_code'])
                ->value('id');
        }

        return response()->json([
            'outcome' => 'SUCCESS: Baseline management data loaded.',
            'user_baselines' => $this->userTableBaselineRows(),
            'game_baselines' => $this->gameBaselineRows($intakeId),
            'intakes' => $this->baselineIntakeRows(),
        ], 200);
    }

    public function F0_VMD_capture_user_table_baseline(Request $request)
    {
        $validated = $request->validate([
            'vmd_user_email' => 'required|email',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        if (!$this->isAdminEmail($validated['vmd_user_email'])) {
            return response()->json([
                'outcome' => 'ERROR: Admin access required.',
            ], 403);
        }

        $createdBy = DB::table('users')->where('email', $validated['vmd_user_email'])->first();
        $baselineName = $validated['name'] ?? 'Users table baseline ' . now()->format('Y-m-d H:i:s');
        $description = $validated['description'] ?? 'Captured from GMUI Users Table Baseline.';

        $baselineId = DB::transaction(function () use ($baselineName, $description, $createdBy) {
            DB::table('user_table_baselines')->update(['is_active' => false, 'updated_at' => now()]);

            $baselineId = DB::table('user_table_baselines')->insertGetId([
                'name' => $baselineName,
                'description' => $description,
                'is_active' => true,
                'created_by_user_id' => $createdBy->id ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $users = DB::table('users')->orderBy('id')->get();

            foreach ($users as $user) {
                DB::table('user_table_baseline_rows')->insert([
                    'baseline_id' => $baselineId,
                    'user_id' => $user->id,
                    'name' => $user->name ?? null,
                    'email' => $user->email ?? null,
                    'password' => $user->password ?? null,
                    'profile_image' => $user->profile_image ?? null,
                    'google_id' => $user->google_id ?? null,
                    'custno' => $user->custno ?? null,
                    'role_id' => $user->role_id ?? null,
                    'role_name' => $user->role_name ?? null,
                    'status' => $user->status ?? null,
                    'updated_by' => $user->updated_by ?? null,
                    'company_name' => $user->company_name ?? null,
                    'gender' => $user->gender ?? null,
                    'location' => $user->location ?? null,
                    'phone_no' => $user->phone_no ?? null,
                    'address_1' => $user->address_1 ?? null,
                    'address_2' => $user->address_2 ?? null,
                    'address_3' => $user->address_3 ?? null,
                    'city' => $user->city ?? null,
                    'state' => $user->state ?? null,
                    'postcode' => $user->postcode ?? null,
                    'is_system_user' => (bool) ($user->is_system_user ?? true),
                    'is_game_user' => (bool) ($user->is_game_user ?? false),
                    'home_intake_id' => $user->home_intake_id ?? null,
                    'action_locked_until' => $user->action_locked_until ?? null,
                    'action_locked_reason' => $user->action_locked_reason ?? null,
                    'action_locked_by_user_id' => $user->action_locked_by_user_id ?? null,
                    'snapshot' => json_encode($user),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $baselineId;
        });

        $rowCount = DB::table('user_table_baseline_rows')->where('baseline_id', $baselineId)->count();

        return response()->json([
            'outcome' => 'SUCCESS: Users table baseline captured.',
            'baseline_id' => $baselineId,
            'row_count' => $rowCount,
        ], 200);
    }

    public function F0_VMD_restore_user_table_baseline(Request $request)
    {
        $validated = $request->validate([
            'vmd_user_email' => 'required|email',
            'baseline_id' => 'nullable|integer',
        ]);

        if (!$this->isAdminEmail($validated['vmd_user_email'])) {
            return response()->json([
                'outcome' => 'ERROR: Admin access required.',
            ], 403);
        }

        $baseline = null;
        if (!empty($validated['baseline_id'])) {
            $baseline = DB::table('user_table_baselines')->where('id', $validated['baseline_id'])->first();
        } else {
            $baseline = DB::table('user_table_baselines')
                ->where('is_active', true)
                ->orderBy('created_at', 'DESC')
                ->first();
        }

        if (!$baseline) {
            return response()->json([
                'outcome' => 'ERROR: Users table baseline not found.',
            ], 404);
        }

        $result = DB::transaction(function () use ($baseline) {
            $rows = DB::table('user_table_baseline_rows')->where('baseline_id', $baseline->id)->get();
            $baselineUserIds = $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            $deleted = DB::table('users')->whereNotIn('id', $baselineUserIds)->delete();
            $updated = 0;
            $created = 0;

            foreach ($rows as $row) {
                $values = [
                    'custno' => $row->custno,
                    'name' => $row->name,
                    'role_id' => $row->role_id,
                    'role_name' => $row->role_name,
                    'status' => $row->status,
                    'email' => $row->email,
                    'password' => $row->password,
                    'is_system_user' => (bool) $row->is_system_user,
                    'is_game_user' => (bool) $row->is_game_user,
                    'home_intake_id' => $row->home_intake_id,
                    'action_locked_until' => $row->action_locked_until,
                    'action_locked_reason' => $row->action_locked_reason,
                    'action_locked_by_user_id' => $row->action_locked_by_user_id,
                    'updated_by' => $row->updated_by,
                    'google_id' => $row->google_id,
                    'profile_image' => $row->profile_image,
                    'company_name' => $row->company_name,
                    'gender' => $row->gender,
                    'location' => $row->location,
                    'address_1' => $row->address_1,
                    'address_2' => $row->address_2,
                    'address_3' => $row->address_3,
                    'city' => $row->city,
                    'state' => $row->state,
                    'postcode' => $row->postcode,
                    'phone_no' => $row->phone_no,
                ];

                if (DB::table('users')->where('id', $row->user_id)->exists()) {
                    DB::table('users')->where('id', $row->user_id)->update($values);
                    $updated += 1;
                } else {
                    DB::table('users')->insert(array_merge([
                        'id' => $row->user_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ], $values));
                    $created += 1;
                }
            }

            return [
                'deleted' => $deleted,
                'updated' => $updated,
                'created' => $created,
                'total' => $rows->count(),
            ];
        });

        return response()->json([
            'outcome' => 'SUCCESS: Users table exactly restored from baseline.',
            'baseline_id' => $baseline->id,
            'restored_rows' => $result['total'],
            'updated_rows' => $result['updated'],
            'created_rows' => $result['created'],
            'deleted_extra_rows' => $result['deleted'],
        ], 200);
    }

    public function F0_VMD_delete_user_table_baseline(Request $request)
    {
        $validated = $request->validate([
            'vmd_user_email' => 'required|email',
            'baseline_id' => 'required|integer|exists:user_table_baselines,id',
        ]);

        if (!$this->isAdminEmail($validated['vmd_user_email'])) {
            return response()->json([
                'outcome' => 'ERROR: Admin access required.',
            ], 403);
        }

        DB::table('user_table_baselines')->where('id', $validated['baseline_id'])->delete();

        return response()->json([
            'outcome' => 'SUCCESS: Users table baseline deleted.',
            'baseline_id' => (int) $validated['baseline_id'],
        ], 200);
    }

    public function F0_VMD_capture_game_user_baseline(Request $request)
    {
        $validated = $request->validate([
            'vmd_user_email' => 'required|email',
            'game_intake_code' => 'required|string|exists:game_intakes,code',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        if (!$this->isAdminEmail($validated['vmd_user_email'])) {
            return response()->json([
                'outcome' => 'ERROR: Admin access required.',
            ], 403);
        }

        $intake = DB::table('game_intakes')->where('code', $validated['game_intake_code'])->first();
        $intakeId = (int) $intake->id;
        $createdBy = DB::table('users')->where('email', $validated['vmd_user_email'])->first();
        $baselineName = $validated['name'] ?? "{$intake->code} student baseline " . now()->format('Y-m-d H:i:s');
        $description = $validated['description'] ?? "Captured from GMUI Reset Baseline for {$intake->code}.";

        $baselineId = DB::transaction(function () use ($intakeId, $baselineName, $description, $createdBy) {
            DB::table('game_baselines')
                ->where('intake_id', $intakeId)
                ->update(['is_active' => false, 'updated_at' => now()]);

            $baselineId = DB::table('game_baselines')->insertGetId([
                'intake_id' => $intakeId,
                'name' => $baselineName,
                'description' => $description,
                'is_active' => true,
                'created_by_user_id' => $createdBy->id ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $gameUsers = DB::table('game_users')
                ->where('intake_id', $intakeId)
                ->orderBy('id')
                ->get();

            foreach ($gameUsers as $gameUser) {
                DB::table('game_baseline_users')->insert([
                    'baseline_id' => $baselineId,
                    'game_user_id' => $gameUser->id,
                    'first_name' => $gameUser->first_name ?? null,
                    'surname' => $gameUser->surname ?? null,
                    'preferred_name' => $gameUser->preferred_name ?? null,
                    'display_name' => $gameUser->display_name ?? null,
                    'email' => $gameUser->email ?? null,
                    'special_needs' => $gameUser->special_needs ?? null,
                    'game_role' => $gameUser->game_role ?? null,
                    'game_status' => $gameUser->game_status ?? null,
                    'is_spy' => (bool) ($gameUser->is_spy ?? false),
                    'is_protector' => (bool) ($gameUser->is_protector ?? false),
                    'action_locked_until' => $gameUser->action_locked_until ?? null,
                    'action_locked_reason' => $gameUser->action_locked_reason ?? null,
                    'action_locked_by_game_user_id' => $gameUser->action_locked_by_game_user_id ?? null,
                    'eliminated_at' => $gameUser->eliminated_at ?? null,
                    'eliminated_by_game_user_id' => $gameUser->eliminated_by_game_user_id ?? null,
                    'metadata' => $gameUser->metadata ?? null,
                    'snapshot' => json_encode($gameUser),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $baselineId;
        });

        return response()->json([
            'outcome' => 'SUCCESS: Student baseline captured.',
            'baseline_id' => $baselineId,
            'game_intake_code' => $intake->code,
            'row_count' => DB::table('game_baseline_users')->where('baseline_id', $baselineId)->count(),
        ], 200);
    }

    public function F0_VMD_restore_game_user_baseline(Request $request)
    {
        $validated = $request->validate([
            'vmd_user_email' => 'required|email',
            'baseline_id' => 'required|integer|exists:game_baselines,id',
            'game_intake_code' => 'required|string|exists:game_intakes,code',
        ]);

        if (!$this->isAdminEmail($validated['vmd_user_email'])) {
            return response()->json([
                'outcome' => 'ERROR: Admin access required.',
            ], 403);
        }

        $intake = DB::table('game_intakes')->where('code', $validated['game_intake_code'])->first();
        $baseline = DB::table('game_baselines')
            ->where('id', $validated['baseline_id'])
            ->where('intake_id', $intake->id)
            ->first();

        if (!$baseline) {
            return response()->json([
                'outcome' => 'ERROR: Student baseline not found for selected Class Intake.',
            ], 404);
        }

        $gameUserColumns = collect(Schema::getColumnListing('game_users'))->flip();

        $result = DB::transaction(function () use ($baseline, $gameUserColumns) {
            $rows = DB::table('game_baseline_users')->where('baseline_id', $baseline->id)->get();
            $baselineGameUserIds = $rows->pluck('game_user_id')->map(fn ($id) => (int) $id)->all();
            $deleted = DB::table('game_users')
                ->where('intake_id', $baseline->intake_id)
                ->whereNotIn('id', $baselineGameUserIds)
                ->delete();
            $updated = 0;
            $created = 0;

            foreach ($rows as $row) {
                $snapshot = json_decode($row->snapshot ?: '{}', true) ?: [];
                $values = collect($snapshot)
                    ->only($gameUserColumns->keys()->all())
                    ->except(['id', 'created_at', 'updated_at'])
                    ->toArray();

                $values = array_merge($values, [
                    'intake_id' => $baseline->intake_id,
                    'first_name' => $row->first_name,
                    'surname' => $row->surname,
                    'preferred_name' => $row->preferred_name,
                    'display_name' => $row->display_name,
                    'email' => $row->email,
                    'special_needs' => $row->special_needs,
                    'game_role' => $row->game_role,
                    'game_status' => $row->game_status,
                    'is_spy' => (bool) $row->is_spy,
                    'is_protector' => (bool) $row->is_protector,
                    'action_locked_until' => $row->action_locked_until,
                    'action_locked_reason' => $row->action_locked_reason,
                    'action_locked_by_game_user_id' => $row->action_locked_by_game_user_id,
                    'eliminated_at' => $row->eliminated_at,
                    'eliminated_by_game_user_id' => $row->eliminated_by_game_user_id,
                    'metadata' => $row->metadata,
                    'updated_at' => now(),
                ]);

                $values = collect($values)
                    ->map(fn ($value) => is_array($value) || is_object($value) ? json_encode($value) : $value)
                    ->toArray();

                if (DB::table('game_users')->where('id', $row->game_user_id)->exists()) {
                    DB::table('game_users')->where('id', $row->game_user_id)->update($values);
                    $updated += 1;
                } else {
                    DB::table('game_users')->insert(array_merge([
                        'id' => $row->game_user_id,
                        'created_at' => now(),
                    ], $values));
                    $created += 1;
                }
            }

            return [
                'deleted' => $deleted,
                'updated' => $updated,
                'created' => $created,
                'total' => $rows->count(),
            ];
        });

        return response()->json([
            'outcome' => 'SUCCESS: Student baseline restored for selected Class Intake.',
            'baseline_id' => $baseline->id,
            'game_intake_code' => $intake->code,
            'restored_rows' => $result['total'],
            'updated_rows' => $result['updated'],
            'created_rows' => $result['created'],
            'deleted_extra_rows' => $result['deleted'],
        ], 200);
    }

    public function F0_VMD_delete_game_user_baseline(Request $request)
    {
        $validated = $request->validate([
            'vmd_user_email' => 'required|email',
            'baseline_id' => 'required|integer|exists:game_baselines,id',
            'game_intake_code' => 'required|string|exists:game_intakes,code',
        ]);

        if (!$this->isAdminEmail($validated['vmd_user_email'])) {
            return response()->json([
                'outcome' => 'ERROR: Admin access required.',
            ], 403);
        }

        $intake = DB::table('game_intakes')->where('code', $validated['game_intake_code'])->first();
        $deleted = DB::table('game_baselines')
            ->where('id', $validated['baseline_id'])
            ->where('intake_id', $intake->id)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'outcome' => 'ERROR: Student baseline not found for selected Class Intake.',
            ], 404);
        }

        return response()->json([
            'outcome' => 'SUCCESS: Student baseline deleted.',
            'baseline_id' => (int) $validated['baseline_id'],
            'game_intake_code' => $intake->code,
        ], 200);
    }









    public function F0_VMD_verify_2fa_code(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'code' => 'required|digits:6',
            'identity_type' => 'nullable|string|in:staff,student',
        ]);

        $user_id = $request->input('user_id');
        $submitted_code = $request->input('code');
        $identityType = $request->input('identity_type', 'staff');
        $cached_code = cache($this->twoFactorCacheKey($identityType, (int) $user_id));

        if (! $cached_code || ! hash_equals((string) $cached_code, (string) $submitted_code)) {
            return response()->json([
                'outcome' => 'ERROR: Invalid or expired code.',
            ], 401);
        }


        // Clear code once used
        cache()->forget($this->twoFactorCacheKey($identityType, (int) $user_id));

        if ($identityType === 'student') {
            $gameUser = DB::table('game_users')
                ->leftJoin('game_intakes', 'game_users.intake_id', '=', 'game_intakes.id')
                ->where('game_users.id', $user_id)
                ->select(
                    'game_users.*',
                    'game_intakes.code as intake_code',
                    'game_intakes.name as intake_name',
                    'game_intakes.active_week as intake_active_week'
                )
                ->first();

            if (! $gameUser) {
                return response()->json([
                    'outcome' => 'ERROR: Student game user not found.',
                ], 404);
            }

            $gameUserStatus = strtoupper((string) $gameUser->game_status);

            if ($gameUserStatus === 'BANNED' && $this->paneOneBlocksBannedLogin($gameUser)) {
                return response()->json([
                    'outcome' => 'STUDENT_LOGIN_DENIED',
                    'errors' => 'Your class intake account has been banned.',
                ], 403);
            }

            if ($gameUserStatus === 'DELETED') {
                return response()->json([
                    'outcome' => 'STUDENT_LOGIN_DENIED',
                    'errors' => 'Your class intake account has been deleted.',
                ], 403);
            }

            $studentCustno = 900000 + (int) $gameUser->id;
            $studentName = $gameUser->display_name ?: trim(($gameUser->preferred_name ?: $gameUser->first_name) . ' ' . $gameUser->surname);
            $pendingLogin = cache("2fa_pending_student_login_{$gameUser->id}") ?: [];
            cache()->forget("2fa_pending_student_login_{$gameUser->id}");

            DB::table('game_users')->where('id', $gameUser->id)->update([
                'last_login_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('user_login_history')->insert([
                'email' => $gameUser->email,
                'custno' => $studentCustno,
                'name' => $studentName,
                'login_identity_type' => 'student',
                'created_at' => now(),
                'ip_address' => $pendingLogin['real_ip'] ?? null,
                'ip_address_v4' => $pendingLogin['ip_address_v4'] ?? null,
                'ip_address_v6' => $pendingLogin['ip_address_v6'] ?? null,
                'user_country' => $pendingLogin['user_country'] ?? null,
                'user_region' => $pendingLogin['user_region'] ?? null,
                'user_city' => $pendingLogin['user_city'] ?? null,
                'user_ZipCode' => $pendingLogin['user_ZipCode'] ?? null,
                'user_timezone' => $pendingLogin['user_timezone'] ?? null,
                'user_agent' => $pendingLogin['user_agent'] ?? $request->header('User-Agent'),
            ]);

            return response()->json([
                'outcome' => 'SUCCESS: Student game user successfully extracted.',
                'id' => $gameUser->id,
                'custno' => $studentCustno,
                'profile_image' => $gameUser->profile_image,
                'google_id' => null,
                'role' => $gameUser->game_role,
                'role_name' => $gameUser->game_role,
                'email' => $gameUser->email,
                'name' => $studentName,
                'username' => $gameUser->email,
                'identity_type' => 'student',
                'is_system_user' => false,
                'is_game_user' => true,
                'game_user_id' => $gameUser->id,
                'game_intake_id' => $gameUser->intake_id,
                'game_intake_code' => $gameUser->intake_code,
                'game_intake_name' => $gameUser->intake_name,
                'game_active_week' => $gameUser->intake_active_week,
                'must_change_password' => (int) $gameUser->must_change_password === 1,
                'user_agent' => $pendingLogin['user_agent'] ?? $request->header('User-Agent'),
            ]);
        }

        $user = User::find($user_id);
        if (!$user) {
            return response()->json([
                'outcome' => 'ERROR: User not found.',
            ], 404);
        }

        // Return the same full login payload as F0_VMD_login_user() would
        return response()->json([
            'outcome' => 'SUCCESS: Existing user successfully extracted.',
            'id' => $user->id,
            'custno' => $user->custno,
            'email' => $user->email,
            'name' => $user->name,
            'username' => $user->email,
            'profile_image' => $user->profile_image,
            // Add anything else you normally return here
        ]);
    }











    public function F0_VMD_resend_2fa(Request $request)
    {
        $user_id = $request->input('user_id');
        $email = $request->input('email');
        $identityType = $request->input('identity_type', 'staff');

        if (!$user_id || !$email) {
            return response()->json(['outcome' => 'ERROR: Missing user_id or email.'], 400);
        }

        if (!$this->dashboardSettingBoolean('login_2fa_enabled', true)) {
            return response()->json(['outcome' => 'ERROR: 2FA is not currently enabled.'], 400);
        }

        if ($identityType === 'student') {
            $exists = DB::table('game_users')
                ->where('id', $user_id)
                ->where('email', $email)
                ->exists();
        } else {
            $exists = User::where('id', $user_id)
                ->where('email', $email)
                ->exists();
        }

        if (! $exists) {
            return response()->json(['outcome' => 'ERROR: User not found.'], 404);
        }

        try {
            $authentication_code = $this->sendTwoFactorCodeForIdentity($identityType, (int) $user_id, $email);
        } catch (\Exception $e) {
            return response()->json([
                'outcome' => 'ERROR: Failed to send email.',
                'error' => $e->getMessage()
            ], 500);
        }

        return response()->json([
            'outcome' => 'SUCCESS: New 2FA code sent.',
            'code_preview' => app()->environment('local') ? $authentication_code : null
        ]);
    }













    // ***** end of PHP file  ****


    public function F0_VMD_user_heartbeat(Request $request)
    {
        $validated = $request->validate([
            'vmd_user.id' => 'required|integer',
            'vmd_user.email' => 'required|email',
            'vmd_user.name' => 'nullable|string|max:255',
            'vmd_user.is_game_user' => 'nullable|boolean',
            'vmd_user.identity_type' => 'nullable|string|max:30',
            'vmd_user.game_user_id' => 'nullable|integer',
            'current_path' => 'nullable|string|max:500',
            'client_sent_at' => 'nullable|string|max:80',
            'vmd_ip_address_v4' => 'nullable|string|max:45',
            'vmd_ip_address_v6' => 'nullable|string|max:45',
        ]);

        $userPayload = $validated['vmd_user'];
        $email = $userPayload['email'];
        $isGameUser = (bool) ($userPayload['is_game_user'] ?? false)
            || (($userPayload['identity_type'] ?? null) === 'student')
            || ! empty($userPayload['game_user_id']);

        if ($email === 'member@jsonapi.com') {
            return response()->json([
                'ok' => true,
                'recorded' => false,
                'reason' => 'guest_user_ignored',
            ], 200);
        }

        $identityType = $isGameUser ? 'student' : 'staff';
        $user = null;
        $gameUser = null;

        if ($isGameUser) {
            $gameUserQuery = DB::table('game_users')->where('email', $email);

            if (! empty($userPayload['game_user_id'])) {
                $gameUserQuery->where('id', $userPayload['game_user_id']);
            } else {
                $gameUserQuery->where('id', $userPayload['id']);
            }

            $gameUser = $gameUserQuery->first();

            if (! $gameUser) {
                return response()->json([
                    'ok' => false,
                    'recorded' => false,
                    'reason' => 'game_user_not_found',
                ], 404);
            }

            if (in_array($gameUser->game_status, ['BANNED', 'DELETED'], true)) {
                return response()->json([
                    'ok' => true,
                    'recorded' => false,
                    'reason' => 'inactive_game_user_ignored',
                ], 200);
            }
        } else {
            $user = DB::table('users')
                ->where('id', $userPayload['id'])
                ->where('email', $email)
                ->first();

            if (! $user) {
                return response()->json([
                    'ok' => false,
                    'recorded' => false,
                    'reason' => 'user_not_found',
                ], 404);
            }

            if (in_array($user->status, ['BANNED', 'DELETED'], true)) {
                return response()->json([
                    'ok' => true,
                    'recorded' => false,
                    'reason' => 'inactive_user_ignored',
                ], 200);
            }
        }

        $clientIp = $this->resolveClientIpAddresses($request);
        $ipAddressV4 = $clientIp['ip_address_v4'];
        $ipAddressV6 = $clientIp['ip_address_v6'];

        $clientReportedIpV4 = $this->normalizeIpAddress($request->input('vmd_ip_address_v4'));
        if ($clientReportedIpV4 && $clientReportedIpV4['ip_address_v4']) {
            $ipAddressV4 = $clientReportedIpV4['ip_address_v4'];
        }

        $clientReportedIpV6 = $this->normalizeIpAddress($request->input('vmd_ip_address_v6'));
        if ($clientReportedIpV6 && $clientReportedIpV6['ip_address_v6']) {
            $ipAddressV6 = $clientReportedIpV6['ip_address_v6'];
        }

        $realIp = $ipAddressV4 ?: $ipAddressV6 ?: $clientIp['ip_address'];

        $clientSentAt = null;
        if (!empty($validated['client_sent_at'])) {
            try {
                $clientSentAt = Carbon::parse($validated['client_sent_at']);
            } catch (\Throwable $error) {
                $clientSentAt = null;
            }
        }

        $now = Carbon::now();
        $presenceKey = $isGameUser ? 'student:' . $gameUser->id : 'staff:' . $user->id;
        $presence = DB::table('user_presence')->where('presence_key', $presenceKey)->first();
        $displayName = $isGameUser
            ? ($gameUser->display_name ?: trim(($gameUser->preferred_name ?: $gameUser->first_name) . ' ' . $gameUser->surname))
            : $user->name;

        $presenceData = [
            'identity_type' => $identityType,
            'user_id' => $isGameUser ? null : $user->id,
            'game_user_id' => $isGameUser ? $gameUser->id : null,
            'presence_key' => $presenceKey,
            'email' => $isGameUser ? $gameUser->email : $user->email,
            'name' => $displayName,
            'ip_address' => $realIp,
            'ip_address_v4' => $ipAddressV4,
            'ip_address_v6' => $ipAddressV6,
            'user_agent' => $request->header('User-Agent'),
            'current_path' => $validated['current_path'] ?? null,
            'last_seen_at' => $now,
            'last_client_sent_at' => $clientSentAt,
            'updated_at' => $now,
        ];

        if ($presence) {
            $presenceData['heartbeat_count'] = DB::raw('heartbeat_count + 1');
            DB::table('user_presence')->where('presence_key', $presenceKey)->update($presenceData);
        } else {
            $presenceData['heartbeat_count'] = 1;
            $presenceData['created_at'] = $now;
            DB::table('user_presence')->insert($presenceData);
        }

        return response()->json([
            'ok' => true,
            'recorded' => true,
            'identity_type' => $identityType,
            'user_id' => $isGameUser ? null : $user->id,
            'game_user_id' => $isGameUser ? $gameUser->id : null,
            'presence_key' => $presenceKey,
            'email' => $isGameUser ? $gameUser->email : $user->email,
            'online_window_seconds' => 120,
            'server_seen_at' => $now->toDateTimeString(),
        ], 200);
    }


    public function F0_VMD_get_online_users(Request $request)
    {
        $onlineWindowSeconds = 120;
        $cutoff = Carbon::now()->subSeconds($onlineWindowSeconds);
        $serverTime = Carbon::now();

        $onlineUsers = DB::table('user_presence')
            ->leftJoin('users', function ($join) {
                $join->on('user_presence.user_id', '=', 'users.id')
                    ->where('user_presence.identity_type', '=', 'staff');
            })
            ->leftJoin('game_users', function ($join) {
                $join->on('user_presence.game_user_id', '=', 'game_users.id')
                    ->where('user_presence.identity_type', '=', 'student');
            })
            ->where('user_presence.last_seen_at', '>=', $cutoff)
            ->where(function ($query) {
                $query->where(function ($staffQuery) {
                    $staffQuery->where('user_presence.identity_type', 'staff')
                        ->where('users.email', '<>', 'member@jsonapi.com')
                        ->where(function ($statusQuery) {
                            $statusQuery->whereNull('users.status')
                                ->orWhereNotIn('users.status', ['BANNED', 'DELETED']);
                        });
                })->orWhere(function ($studentQuery) {
                    $studentQuery->where('user_presence.identity_type', 'student')
                        ->whereNotIn('game_users.game_status', ['BANNED', 'DELETED']);
                });
            })
            ->select(
                'user_presence.identity_type',
                'user_presence.user_id',
                'user_presence.game_user_id',
                'user_presence.presence_key',
                'user_presence.email',
                'user_presence.name',
                'user_presence.current_path',
                'user_presence.ip_address',
                'user_presence.ip_address_v4',
                'user_presence.ip_address_v6',
                'user_presence.last_seen_at',
                'user_presence.last_client_sent_at',
                'user_presence.heartbeat_count'
            )
            ->orderBy('user_presence.last_seen_at', 'DESC')
            ->get();

        return response()->json([
            'ok' => true,
            'online_window_seconds' => $onlineWindowSeconds,
            'server_time' => $serverTime->toDateTimeString(),
            'data' => $onlineUsers,
        ], 200);
    }
}
