<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\File;


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

        $message = 'You are currently in a timeout period because you either banned or deleted a fellow user.';

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
        if (! $targetWasActive || ! $this->dashboardSettingBoolean('game_delete_cooldown_enabled', false)) {
            return null;
        }

        $minutes = max(0, $this->dashboardSettingInteger('game_delete_cooldown_minutes', 5));
        if ($minutes === 0) {
            return null;
        }

        $actorGameUser = GameUser::where('email', $actorEmail)->first();
        if (! $actorGameUser || intval($actorGameUser->id) === intval($targetGameUser->id)) {
            return null;
        }

        $lockedUntil = now()->addMinutes($minutes);
        $actorGameUser->action_locked_until = $lockedUntil;
        $actorGameUser->action_locked_reason = ucfirst(strtolower($action)) . ' a fellow user';
        $actorGameUser->action_locked_by_game_user_id = intval($targetGameUser->id);
        $actorGameUser->save();

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

    private function canManageGameUsers(?string $email): bool
    {
        if (! $email) {
            return false;
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            $roleName = $user->role_name ?: optional($user->roles()->first())->name;

            return in_array(strtolower((string) $roleName), ['admin', 'protector'], true);
        }

        $gameUser = GameUser::where('email', $email)->first();
        $roleName = $gameUser?->game_role;

        return in_array(strtolower((string) $roleName), ['admin', 'protector'], true);
    }

    private function staffRoleName(User $user): string
    {
        return (string) ($user->role_name ?: optional($user->roles()->first())->name);
    }

    private function isStaffAdmin(User $user): bool
    {
        return strcasecmp($this->staffRoleName($user), 'Admin') === 0;
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
            ->join('users', 'users.id', '=', 'staff_intake_assignments.staff_user_id')
            ->where('staff_intake_assignments.active', true)
            ->select(
                'staff_intake_assignments.game_intake_id',
                'staff_intake_assignments.assignment_type',
                'users.id',
                'users.name',
                'users.email',
                'users.role_name',
                'users.profile_image'
            )
            ->orderBy('users.name')
            ->get()
            ->groupBy('game_intake_id')
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

    public function F0_VMD_get_class_intake_management_data(Request $request)
    {
        $adminUser = $this->staffAdminFromRequest($request);
        if (! $adminUser) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Permission Denied: only Staff users with Admin powers can manage Class Intake assignments.',
            ], 403);
        }

        return response()->json([
            'outcome' => 'SUCCESS',
            'data' => $this->classIntakeManagementPayload(),
        ], 200);
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

        $validator = Validator::make($request->all(), [
            'game_intake_id' => 'required|integer|exists:game_intakes,id',
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

        $gameIntakeId = (int) $request->input('game_intake_id');
        $staffUserIds = collect($request->input('staff_user_ids', []))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        DB::transaction(function () use ($gameIntakeId, $staffUserIds, $adminUser) {
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
        });

        return response()->json([
            'outcome' => 'SUCCESS',
            'message' => 'Staff intake assignments saved.',
            'data' => $this->classIntakeManagementPayload(),
        ], 200);
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

    private function createProtectedAccountWarningNotifications(int $auditHistoryId, array $event): void
    {
        $recipients = DB::table('users')
            ->where(function ($query) {
                $query->whereIn(DB::raw('LOWER(role_name)'), ['admin', 'protector'])
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
        $actor = $row->actor_email
            ? DB::table('users')->where('email', $row->actor_email)->first()
            : null;
        $metadata = $this->normalizeNotificationMetadata($row->metadata);

        if ($actor && empty($metadata['actorImage'])) {
            $metadata['actorImage'] = $actor->profile_image ?? '';
        }

        if ($actor && empty($metadata['updatedBy'])) {
            $metadata['updatedBy'] = $actor->email;
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

                $creatorRole = DB::table('roles')->whereRaw('LOWER(name) = ?', ['creator'])->first();

                return response()->json([
                    "outcome" => 'SUCCESS: Existing game user successfully extracted.',
                    "id" => intval($gameUser->id),
                    "profile_image" => $gameUser->profile_image,
                    "custno" => 900000 + intval($gameUser->id),
                    "role_id" => $creatorRole ? intval($creatorRole->id) : 2,
                    "role_name" => $gameUser->game_role,
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

                return response()->json([
                    "outcome" => 'SUCCESS: Existing game user successfully extracted.',
                    "id" => intval($gameUser->id),
                    "profile_image" => $gameUser->profile_image,
                    "custno" => 900000 + intval($gameUser->id),
                    "role_id" => null,
                    "role_name" => $gameUser->game_role,
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

                if ($gameUserStatus === 'BANNED') {
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

        if ($data['id'] === 1) {
            $this->logProtectedAccountEditBlocked($request, $data, $data['vmd_audit_reason'] ?? 'ban protected account');
            $response['outcome'] = "FAIL";
            $response['message'] = "Permission Denied:  (You can NEVER EVER edit the Admin account.)";
            return response()->json($response, 403);
        }

        // Update the user record based on custno
        $user = User::where('id', $data['id'])->first();

        if ($user) {
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

        if ($user->status === 'BANNED') {
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

        if ($data['id'] === 1) {
            $this->logProtectedAccountEditBlocked($request, $data, $data['vmd_audit_reason'] ?? 'delete protected account');
            $response['outcome'] = "FAIL";
            $response['message'] = "Permission Denied:  (You can NEVER EVER edit the Admin account.)";
            return response()->json($response, 403);
        }

        // Update the user record based on custno
        $user = User::where('id', $data['id'])->first();

        if ($user) {
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

        // Get the total number of records in the table
        $recordsTotal = DB::table('user_login_history')->count();
        $newLoginHistoryQuery = function () {
            return DB::table('user_login_history')
                ->leftJoin('users', 'user_login_history.email', '=', 'users.email')
                ->leftJoin('game_users', 'user_login_history.email', '=', 'game_users.email')
                ->leftJoin('game_intakes', 'game_users.intake_id', '=', 'game_intakes.id');
        };
        $applyGameIntakeScope = function ($query) use ($gameIntakeId, $gameIntakeCode) {
            if (! $gameIntakeId && ! $gameIntakeCode) {
                return $query;
            }

            return $query->where(function ($scopeQuery) use ($gameIntakeId, $gameIntakeCode) {
                $scopeQuery->whereNull('game_users.id');

                $scopeQuery->orWhere(function ($studentQuery) use ($gameIntakeId, $gameIntakeCode) {
                    if ($gameIntakeCode) {
                        $studentQuery->where('game_intakes.code', $gameIntakeCode);
                    } elseif ($gameIntakeId) {
                        $studentQuery->where('game_users.intake_id', $gameIntakeId);
                    }
                });
            });
        };

        // Apply filters based on method
        switch ($method) {
            case 'single user':
                $login_history_list = $applyGameIntakeScope($newLoginHistoryQuery())
                    ->where('user_login_history.email', $email)
                    ->select(
                        'user_login_history.*',
                        'users.google_id',
                        DB::raw('COALESCE(users.profile_image, game_users.profile_image) as profile_image')
                    )
                    ->orderBy('user_login_history.created_at', 'DESC')
                    ->get();

                break;

            case 'Staff Logins':
                $login_history_list = $applyGameIntakeScope($newLoginHistoryQuery())
                    ->where(function ($query) {
                        $query->where('user_login_history.login_identity_type', 'staff')
                            ->orWhereNull('user_login_history.login_identity_type');
                    })
                    ->select(
                        'user_login_history.*',
                        'users.google_id',
                        DB::raw('COALESCE(users.profile_image, game_users.profile_image) as profile_image')
                    )
                    ->orderBy('user_login_history.created_at', 'DESC')
                    ->limit(100)
                    ->get();

                break;

            case 'Student Logins':
                $login_history_list = $applyGameIntakeScope($newLoginHistoryQuery())
                    ->where('user_login_history.login_identity_type', 'student')
                    ->select(
                        'user_login_history.*',
                        'users.google_id',
                        DB::raw('COALESCE(users.profile_image, game_users.profile_image) as profile_image')
                    )
                    ->orderBy('user_login_history.created_at', 'DESC')
                    ->limit(100)
                    ->get();

                break;

            default:
                $login_history_list = $applyGameIntakeScope($newLoginHistoryQuery())
                    ->select(
                        'user_login_history.*',
                        'users.google_id',
                        DB::raw('COALESCE(users.profile_image, game_users.profile_image) as profile_image')
                    )
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
        $viewer = $requestEmail ? DB::table('users')->where('email', $requestEmail)->first() : null;
        $canViewProtectedSecurityAudits = $viewer && in_array($viewer->role_name, ['Admin', 'Protector'], true);


        $auditHistoryQuery = DB::table('user_audit_history')
            ->leftJoin('users', 'users.id', '=', DB::raw('user_audit_history.custno - 100000'))
            ->leftJoin('game_users', 'game_users.id', '=', DB::raw('user_audit_history.custno - 900000'))
            ->leftJoin('game_intakes', 'game_users.intake_id', '=', 'game_intakes.id')
            ->select(
                'user_audit_history.*',
                DB::raw("COALESCE(users.name, game_users.display_name, TRIM(CONCAT(COALESCE(game_users.preferred_name, game_users.first_name, ''), ' ', COALESCE(game_users.surname, '')))) as target_name"),
                DB::raw('COALESCE(users.email, game_users.email) as target_email')
            );

        if ($gameIntakeId || $gameIntakeCode) {
            $auditHistoryQuery->where(function ($query) use ($gameIntakeId, $gameIntakeCode) {
                $query->whereNull('game_users.id');

                $query->orWhere(function ($studentQuery) use ($gameIntakeId, $gameIntakeCode) {
                    if ($gameIntakeCode) {
                        $studentQuery->where('game_intakes.code', $gameIntakeCode);
                    } elseif ($gameIntakeId) {
                        $studentQuery->where('game_users.intake_id', $gameIntakeId);
                    }
                });
            });
        }

        if (! $canViewProtectedSecurityAudits) {
            $auditHistoryQuery
                ->where('user_audit_history.comments', 'not like', 'Protected account edit blocked:%')
                ->where('user_audit_history.comments', 'not like', 'Protected account warning;%');
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
            return [
                "type" => 'user_audit_history',
                "id" => $row->id, // Now using the actual record number
                'attributes' => [
                    "custno" => $row->custno,
                    "comments" => $row->comments,
                    "created_by_email" => $row->created_by_email,
                    "clerk_id" => $row->clerk_id,
                    "created_by_ip_address" => $row->created_by_ip_address,
                    "target_name" => $row->target_name,
                    "target_email" => $row->target_email,
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

        $notification = $this->createPersistedNotification([
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
        ]);

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

        // Fetch the real user IP from Cloudflare
        $realIp = $request->header('X-Forwarded-For');
        $realIp = $realIp ? explode(',', $realIp)[0] : $request->ip();
        $realIp = trim($realIp);

        // IPinfo API credentials
        $accessToken = '4af1c2308a696c';
        $isLocalIp = in_array($realIp, ['127.0.0.1', '::1', 'localhost'], true)
            || str_starts_with($realIp, '192.168.')
            || str_starts_with($realIp, '10.')
            || preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $realIp);

        if ($isLocalIp) {
            $country = 'AU';
        } else {
            $apiUrl = "http://ipinfo.io/{$realIp}/json?token={$accessToken}";
            $pageContent = file_get_contents($apiUrl);
            if ($pageContent === false) {
                return response()->json(['errors' => 'Failed to fetch geolocation data during F0_VMD_updateUser().'], 500);
            }

            $parsedJson = json_decode($pageContent);
            $country = $parsedJson->country ?? null;
        }

        if ($country !== 'AU') {
            return response()->json(['errors' => "Permission Denied:  (You can only perform updates if you are in Australia)"], 403);
        }

        if ($data['id'] == 1 && ! $protectedAdminSelfAvatarUpdate) {
            return response()->json(['errors' => "Permission Denied:  (You cannot edit the Admin account)"], 403);
        }

        // Update the user record based on ID
        $user = User::where('id', $data['id'])->first();

        if ($user) {
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
            $user->password     = isset($data['password']) ? bcrypt($data['password']) : $user->password;

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

        $isSelfUpdate = strcasecmp($gameUser->email, $data['email']) === 0
            && strcasecmp($gameUser->email, $data['vmd_user_email']) === 0;

        if (strcasecmp($gameUser->email, $data['email']) !== 0 || (! $isSelfUpdate && ! $this->canManageGameUsers($data['vmd_user_email']))) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Permission Denied: You can only update your own student profile unless you are Admin or Protector.',
            ], 403);
        }

        DB::table('game_users')->where('id', $data['id'])->update([
            'display_name' => $data['name'],
            'gender' => $data['gender'] ?? null,
            'location' => $data['location'] ?? null,
            'phone_no' => $data['phone_no'] ?? null,
            'languages' => json_encode($data['languages'] ?? []),
            'game_role' => $data['role_name'] ?? $gameUser->game_role,
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

        $isSelfPasswordChange = strcasecmp($gameUser->email, $data['vmd_user_email']) === 0;

        if (! $isSelfPasswordChange && ! $this->canManageGameUsers($data['vmd_user_email'])) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Permission Denied: You can only change your own student password unless you are Admin or Protector.',
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
                'message' => 'Permission Denied: Admin or Protector access required.',
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

        $targetWasActive = strcasecmp((string) $gameUser->game_status, 'ACTIVE') === 0;

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

            $this->createPersistedNotification([
                'recipient_email' => $gameUser->email,
                'actor_email' => $data['vmd_user_email'],
                'type' => 'info',
                'title' => 'You have been unbanned',
                'message' => "{$displayName}, your account has been unbanned by {$data['vmd_user_name']}.",
                'source' => 'user-management',
                'metadata' => [
                    'game_user_id' => intval($gameUser->id),
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











    public function F0_VMD_get_dashboard_settings(Request $request)
    {
        $request->validate([
            'vmd_user_email' => 'required|email',
        ]);

        if (!$this->isAdminEmail($request->input('vmd_user_email'))) {
            return response()->json([
                'outcome' => 'ERROR: Admin access required.',
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
            'settings.login_2fa_enabled' => 'required|boolean',
            'settings.login_2fa_send_to_account' => 'required|boolean',
            'settings.login_2fa_send_to_master' => 'required|boolean',
            'settings.login_2fa_master_email' => 'nullable|email',
            'settings.game_delete_cooldown_enabled' => 'required|boolean',
            'settings.game_delete_cooldown_minutes' => 'required|integer|min:1|max:1440',
        ]);

        if (!$this->isAdminEmail($request->input('vmd_user_email'))) {
            return response()->json([
                'outcome' => 'ERROR: Admin access required.',
            ], 403);
        }

        $settings = $request->input('settings');
        $twoFactorEnabled = (bool) $settings['login_2fa_enabled'];
        $sendToAccount = (bool) $settings['login_2fa_send_to_account'];
        $sendToMaster = (bool) $settings['login_2fa_send_to_master'];
        $masterEmail = $settings['login_2fa_master_email'] ?? '';
        $deleteCooldownEnabled = (bool) $settings['game_delete_cooldown_enabled'];
        $deleteCooldownMinutes = intval($settings['game_delete_cooldown_minutes']);

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
            'game_delete_cooldown_enabled' => $deleteCooldownEnabled ? '1' : '0',
            'game_delete_cooldown_minutes' => (string) $deleteCooldownMinutes,
        ];

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
