<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SseController extends Controller
{
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
