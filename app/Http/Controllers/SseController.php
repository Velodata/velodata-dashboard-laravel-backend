<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SseController extends Controller
{
    private function canReceiveProtectedSecurityEvents(string $email): bool
    {
        $user = DB::table('users')->where('email', $email)->first();

        if (! $user) {
            return false;
        }

        $roleName = strtolower((string) ($user->role_name ?? ''));
        $roleId = (int) ($user->role_id ?? 0);

        return in_array($roleName, ['admin', 'protector'], true) || in_array($roleId, [1, 5], true);
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

        return response()->stream(function () use ($email) {
            set_time_limit(0);
            $lastUpdated = null;
            $canReceiveSecurityEvents = $this->canReceiveProtectedSecurityEvents($email);
            $lastSecurityAuditId = $canReceiveSecurityEvents ? optional($this->getLatestProtectedSecurityAudit())->id : null;

            while (true) {
                if (connection_aborted()) {
                    break;
                }

                $user = DB::table('users')->where('email', $email)->first();

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
                            'updated_by' => $user->updated_by,
                        ]) . "\n\n";

                        $lastUpdated = $user->updated_at;
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
