<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SseController extends Controller
{
    private function isProtectorEmail(?string $email): bool
    {
        if (! $email) {
            return false;
        }

        $normalizedEmail = strtolower(trim($email));
        $user = DB::table('users')->whereRaw('LOWER(email) = ?', [$normalizedEmail])->first();
        if ($user && strtolower((string) ($user->role_name ?? '')) === 'protector') {
            return true;
        }

        $gameUser = DB::table('game_users')->whereRaw('LOWER(email) = ?', [$normalizedEmail])->first();

        return $gameUser && strtolower((string) ($gameUser->game_role ?? '')) === 'protector';
    }

    private function intakeGameSettingBoolean(?int $intakeId, string $key, bool $default = false): bool
    {
        if (! $intakeId || ! $key) {
            return $default;
        }

        $setting = DB::table('game_intake_settings')
            ->where('game_intake_id', $intakeId)
            ->where('key', $key)
            ->value('value');

        if ($setting === null) {
            return $default;
        }

        return in_array(strtolower((string) $setting), ['1', 'true', 'yes', 'on'], true);
    }

    private function protectorActorMaskForDisplay(?string $actorEmail, ?string $intakeCode): ?array
    {
        $actorEmail = $actorEmail ? strtolower(trim($actorEmail)) : '';
        $intakeCode = $intakeCode ? trim($intakeCode) : '';

        if (
            $actorEmail === '' ||
            $intakeCode === '' ||
            ! DB::getSchemaBuilder()->hasTable('protector_actor_masks') ||
            ! $this->isProtectorEmail($actorEmail)
        ) {
            return null;
        }

        $intake = DB::table('game_intakes')->where('code', $intakeCode)->first();
        if (! $intake || ! $this->intakeGameSettingBoolean((int) $intake->id, 'game_protector_actor_impersonation', false)) {
            return null;
        }

        $mask = DB::table('protector_actor_masks')
            ->where('protector_email', $actorEmail)
            ->where('game_intake_code', $intakeCode)
            ->where('enabled', true)
            ->first();

        if (! $mask || ! $mask->masked_as_email) {
            return null;
        }

        $maskedStudent = DB::table('game_users')
            ->where('intake_id', $intake->id)
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($mask->masked_as_email))])
            ->first();

        if (! $maskedStudent) {
            return null;
        }

        $displayName = $maskedStudent->display_name
            ?: trim(($maskedStudent->preferred_name ?: $maskedStudent->first_name) . ' ' . $maskedStudent->surname);

        return [
            'name' => $displayName ?: $maskedStudent->email,
            'email' => $maskedStudent->email,
            'role_name' => $maskedStudent->game_role,
            'status' => strtoupper((string) ($maskedStudent->game_status ?: 'ACTIVE')),
            'profile_image' => $maskedStudent->profile_image,
        ];
    }

    private function actorDisplayPayload(?string $actorEmail, ?string $intakeCode): array
    {
        $actorEmail = $actorEmail ? strtolower(trim($actorEmail)) : '';
        $actor = $actorEmail ? DB::table('users')->whereRaw('LOWER(email) = ?', [$actorEmail])->first() : null;
        $actorGameUser = (! $actor && $actorEmail)
            ? DB::table('game_users')->whereRaw('LOWER(email) = ?', [$actorEmail])->first()
            : null;
        $mask = $this->protectorActorMaskForDisplay($actorEmail, $intakeCode);

        if ($mask) {
            return [
                'updated_by' => $actorEmail,
                'updated_by_email' => $mask['email'],
                'updated_by_display' => $mask['name'] ?: $mask['email'],
                'updated_by_role' => $mask['role_name'],
                'updated_by_status' => $mask['status'] ?? 'ACTIVE',
                'actorStatus' => $mask['status'] ?? 'ACTIVE',
                'updated_by_profile_image' => $mask['profile_image'] ?? null,
                'actual_updated_by_email' => $actorEmail,
                'actual_updated_by_display' => $actor->name ?? $actorGameUser->display_name ?? $actorEmail,
                'actor_appearance_applied' => true,
            ];
        }

        return [
            'updated_by' => $actorEmail,
            'updated_by_email' => $actorEmail,
            'updated_by_display' => $actor->name ?? $actorGameUser->display_name ?? $actorEmail,
            'updated_by_role' => $actor->role_name ?? $actorGameUser->game_role ?? null,
            'updated_by_status' => $actor
                ? strtoupper((string) ($actor->status ?: 'ACTIVE'))
                : ($actorGameUser ? strtoupper((string) ($actorGameUser->game_status ?: 'ACTIVE')) : null),
            'actorStatus' => $actor
                ? strtoupper((string) ($actor->status ?: 'ACTIVE'))
                : ($actorGameUser ? strtoupper((string) ($actorGameUser->game_status ?: 'ACTIVE')) : null),
            'updated_by_profile_image' => $actor->profile_image ?? $actorGameUser->profile_image ?? null,
            'actor_appearance_applied' => false,
        ];
    }

    private function canReceiveProtectedSecurityEvents(string $email): bool
    {
        $user = DB::table('users')->where('email', $email)->first();

        if (! $user) {
            return false;
        }

        $roleName = strtolower((string) ($user->role_name ?? ''));
        $roleId = (int) ($user->role_id ?? 0);

        return in_array($roleName, ['admin', 'protector', 'trainer'], true) || in_array($roleId, [1, 5], true);
    }

    private function getLatestProtectedSecurityAudit()
    {
        return DB::table('user_audit_history')
            ->leftJoin('users', 'users.id', '=', DB::raw('user_audit_history.custno - 100000'))
            ->select(
                'user_audit_history.*',
                'users.name as target_name',
                'users.email as target_email'
            )
            ->where(function ($query) {
                $query
                    ->where('user_audit_history.comments', 'like', 'Protected account edit blocked:%')
                    ->orWhere('user_audit_history.comments', 'like', 'Protected account warning;%');
            })
            ->orderBy('user_audit_history.id', 'DESC')
            ->first();
    }

    public function profileUpdates(Request $request)
    {
        $email = $request->header('X-User-Email') ?: $request->query('email');
        $identityType = strtolower((string) $request->query('identity_type', 'staff'));
        $gameUserId = $request->query('game_user_id');
        $origin = $request->headers->get('Origin');
        $allowedOrigins = array_filter(array_map('trim', explode(',', env('SSE_ALLOWED_ORIGINS', ''))));

        if (!$email) {
            abort(400, 'Missing X-User-Email header or email query parameter.');
        }

        $headers = [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];

        if ($origin && in_array($origin, $allowedOrigins, true)) {
            $headers['Access-Control-Allow-Origin'] = $origin;
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        return response()->stream(function () use ($email, $identityType, $gameUserId) {
            set_time_limit(0);
            $lastUpdated = null;
            $canReceiveSecurityEvents = $identityType !== 'student'
                && $this->canReceiveProtectedSecurityEvents($email);
            $lastSecurityAuditId = $canReceiveSecurityEvents ? optional($this->getLatestProtectedSecurityAudit())->id : null;

            while (true) {
                if (connection_aborted()) {
                    break;
                }

                $user = $identityType === 'student'
                    ? null
                    : DB::table('users')->where('email', $email)->first();
                $gameUser = null;

                if ($user) {
                    if ($lastUpdated === null) {
                        $lastUpdated = $user->updated_at;
                    }

                    if ($user->updated_at !== $lastUpdated) {
                        echo "event: profile.updated\n";
                        echo 'data: ' . json_encode([
                            'email' => $user->email,
                            'updated_at' => $user->updated_at,
                            'status' => $user->status,
                            'role_id' => $user->role_id,
                            'role_name' => $user->role_name,
                            'profile_image' => $user->profile_image,
                            'identity_type' => 'staff',
                        ] + $this->actorDisplayPayload($user->updated_by, null)) . "\n\n";

                        $lastUpdated = $user->updated_at;
                    }
                } else {
                    $gameUserQuery = DB::table('game_users')
                        ->leftJoin('game_intakes', 'game_users.intake_id', '=', 'game_intakes.id')
                        ->select(
                            'game_users.*',
                            'game_intakes.code as intake_code',
                            'game_intakes.name as intake_name',
                            'game_intakes.active_week as intake_active_week'
                        );

                    if ($gameUserId) {
                        $gameUserQuery
                            ->where('game_users.id', $gameUserId)
                            ->where('game_users.email', $email);
                    } else {
                        $gameUserQuery->where('game_users.email', $email);
                    }

                    $gameUser = $gameUserQuery->first();

                    if ($gameUser) {
                        if ($lastUpdated === null) {
                            $lastUpdated = $gameUser->updated_at;
                        }

                        if ($gameUser->updated_at !== $lastUpdated) {
                            echo "event: profile.updated\n";
                            echo 'data: ' . json_encode([
                                'email' => $gameUser->email,
                                'updated_at' => $gameUser->updated_at,
                                'status' => strtoupper((string) $gameUser->game_status),
                                'role_name' => $gameUser->game_role,
                                'profile_image' => $gameUser->profile_image,
                                'identity_type' => 'student',
                                'game_user_id' => $gameUser->id,
                                'game_intake_id' => $gameUser->intake_id,
                                'game_intake_code' => $gameUser->intake_code,
                                'game_intake_name' => $gameUser->intake_name,
                                'game_active_week' => $gameUser->intake_active_week,
                                'action_locked_until' => $gameUser->action_locked_until,
                                'action_locked_reason' => $gameUser->action_locked_reason,
                                'action_locked_by_game_user_id' => $gameUser->action_locked_by_game_user_id,
                            ] + $this->actorDisplayPayload($gameUser->updated_by, $gameUser->intake_code)) . "\n\n";

                            $lastUpdated = $gameUser->updated_at;
                        }
                    }
                }

                if ($canReceiveSecurityEvents) {
                    $latestSecurityAudit = $this->getLatestProtectedSecurityAudit();

                    if ($latestSecurityAudit && $latestSecurityAudit->id !== $lastSecurityAuditId) {
                        echo "event: security.protected_edit_blocked\n";
                        echo 'data: ' . json_encode([
                            'id' => $latestSecurityAudit->id,
                            'email' => $email,
                            'target_email' => $latestSecurityAudit->target_email,
                            'target_name' => $latestSecurityAudit->target_name,
                            'actor_email' => $latestSecurityAudit->created_by_email,
                            'actor_name' => $latestSecurityAudit->clerk_id,
                            'message' => $latestSecurityAudit->comments,
                            'created_at' => $latestSecurityAudit->created_at,
                            'created_by_ip_address' => $latestSecurityAudit->created_by_ip_address,
                        ]) . "\n\n";

                        $lastSecurityAuditId = $latestSecurityAudit->id;
                    }
                }

                echo ": keep-alive\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                sleep(5);
            }
        }, 200, $headers);
    }
}
