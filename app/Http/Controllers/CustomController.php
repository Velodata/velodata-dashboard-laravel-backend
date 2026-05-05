<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Response;


use App\Mail\Test2FAMail;
use App\Mail\TwoFactorCodeMail;
use Illuminate\Support\Facades\Mail;
// IJV - 2025.05.30 - Added to allow for 2FA verification emails



use Illuminate\Support\Facades\Log;
// IJV - 2025.06.15 - Added to allow for improved Role permissions logic



use App\Models\User; // Your User Model
use App\Models\UserLogin; // Your User Login Model
use Carbon\Carbon;
use App\Models\Role;


class CustomController extends Controller
{
    private function getRequestIpAddress(Request $request): string
    {
        $realIp = $request->header('X-Forwarded-For');
        $realIp = $realIp ? explode(',', $realIp)[0] : $request->ip();

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
        $currentDate = Carbon::now();
        $userAgent = $request->header('User-Agent');

        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $table_name = 'users';
        $login_log_table = 'user_login_history';

        $emailExists = DB::table($table_name)->where('email', $email)->exists();

        // --- New Google OAuth user registration ---
        if (! $emailExists && $method === "Google OAuth") {
            $password = $google_id;
            $inserted = DB::table($table_name)->insert([
                'email' => $email,
                'password' => bcrypt($google_id),
                'name' => $name,
                'profile_image' => $picture,
                'role_name' => 'creator',
                'role_id' => 2,
                'google_id' => $google_id,
                'created_at' => now(),
                'updated_at' => $currentDate,
            ]);

            if ($inserted) {
                $userId = DB::getPdo()->lastInsertId();
                $custno = $userId + 100000;

                DB::table($table_name)
                    ->where('id', $userId)
                    ->update([
                        'custno' => $custno,
                        'updated_at' => $currentDate,
                    ]);

                DB::table($login_log_table)->insert([
                    'email'         => $email,
                    'custno'        => $custno,
                    'name'          => $name,
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
                    'outcome' => 'SUCCESS: New User successfully added!!!',
                    'custno' => $custno,
                    'role_name' => 'creator',
                    'email' => $email,
                    'name' => $name,
                    'google_id' => $google_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], 200);
            }
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


            // ✅ Only Send 2FA Authentication email for manual logins
            if ($method !== "Google OAuth") {
                $authentication_code = random_int(100000, 999999);
                cache()->put("2fa_code_{$user->id}", $authentication_code, now()->addMinutes(10));
                Mail::to($user->email)->send(new TwoFactorCodeMail($authentication_code));
                Mail::to('ivanvetsich@gmail.com')->send(new TwoFactorCodeMail($authentication_code));

                return response()->json([
                    'outcome' => '2FA_REQUIRED',
                    'user_id' => $user->id,
                    'email' => $user->email,
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

        if (!$user || !$user->roles()->exists()) {
            return response()->json(['permissions' => []]);
        }

        // Assuming single role per user
        $role = $user->roles()->first();

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
                'created_by_ip_address' => $realIp,
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
                'created_by_ip_address' => $realIp,
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
                'created_by_ip_address' => $realIp,
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

        // Get the total number of records in the table
        $recordsTotal = DB::table('user_login_history')->count();

        // Apply filters based on method
        switch ($method) {
            case 'single user':
                $login_history_list = DB::table('user_login_history')
                    ->join('users', 'user_login_history.email', '=', 'users.email')
                    ->where('user_login_history.email', $email)
                    ->select(
                        'user_login_history.*',
                        'users.google_id'
                    )
                    ->orderBy('user_login_history.created_at', 'DESC')
                    ->get();

                break;

            case 'Google Logins Only':
                $login_history_list = DB::table('user_login_history')
                    ->join('users', 'user_login_history.email', '=', 'users.email')
                    ->whereNotNull('users.google_id')
                    ->select(
                        'user_login_history.*',
                        'users.google_id'
                    )
                    ->orderBy('user_login_history.created_at', 'DESC')
                    ->limit(100)
                    ->get();

                break;

            case 'Manual Logins Only':
                $login_history_list = DB::table('user_login_history')
                    ->join('users', 'user_login_history.email', '=', 'users.email')
                    ->whereNull('users.google_id')
                    ->select(
                        'user_login_history.*',
                        'users.google_id'
                    )
                    ->orderBy('user_login_history.created_at', 'DESC')
                    ->limit(100)
                    ->get();

                break;

            default:
                $login_history_list = DB::table('user_login_history')
                    ->join('users', 'user_login_history.email', '=', 'users.email')
                    ->select(
                        'user_login_history.*',
                        'users.google_id'
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
        $viewer = $requestEmail ? DB::table('users')->where('email', $requestEmail)->first() : null;
        $canViewProtectedSecurityAudits = $viewer && in_array($viewer->role_name, ['Admin', 'Protector'], true);


        $auditHistoryQuery = DB::table('user_audit_history')
            ->leftJoin('users', 'users.id', '=', DB::raw('user_audit_history.custno - 100000'))
            ->select(
                'user_audit_history.*',
                'users.name as target_name',
                'users.email as target_email'
            );

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

        if ($data['id'] === 1) {
            $this->logProtectedAccountEditBlocked($request, $data, $data['vmd_audit_reason'] ?? 'update protected account');
            $response['outcome'] = "FAIL";
            $response['message'] = "Permission Denied:  (You can NEVER EVER edit the Admin account.)";
            return response()->json($response, 403);
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

        if ($data['id'] == 1) {
            return response()->json(['errors' => "Permission Denied:  (You cannot edit the Admin account)"], 403);
        }

        // Update the user record based on ID
        $user = User::where('id', $data['id'])->first();

        if ($user) {
            $user->name         = $data['name'] ?? $user->name;
            $user->email        = $data['email'] ?? $user->email;
            $user->role_id      = $data['role_id'] ?? $user->role_id;
            $user->role_name    = $data['role_name'] ?? $user->role_name;
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
                'created_by_ip_address' => $realIp,
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











    public function F0_VMD_verify_2fa_code(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'code' => 'required|digits:6',
        ]);

        $user_id = $request->input('user_id');
        $submitted_code = $request->input('code');
        $cached_code = cache("2fa_code_{$user_id}");

        if ((int) $cached_code !== (int) $submitted_code) {
            return response()->json([
                'outcome' => 'ERROR: Invalid or expired code.',
            ], 401);
        }


        // Clear code once used
        cache()->forget("2fa_code_{$user_id}");

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

        if (!$user_id || !$email) {
            return response()->json(['outcome' => 'ERROR: Missing user_id or email.'], 400);
        }


        $authentication_code = random_int(100000, 999999);
        cache()->put("2fa_code_{$user_id}", $authentication_code, now()->addMinutes(10));


        try {
            // Mail::to($user->email)->send(new TwoFactorCodeMail($authentication_code));
            Mail::to('ivanvetsich@gmail.com')->send(new TwoFactorCodeMail($authentication_code));
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
            'current_path' => 'nullable|string|max:500',
            'client_sent_at' => 'nullable|string|max:80',
        ]);

        $userPayload = $validated['vmd_user'];
        $email = $userPayload['email'];

        if ($email === 'member@jsonapi.com') {
            return response()->json([
                'ok' => true,
                'recorded' => false,
                'reason' => 'guest_user_ignored',
            ], 200);
        }

        $user = DB::table('users')
            ->where('id', $userPayload['id'])
            ->where('email', $email)
            ->first();

        if (!$user) {
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

        $realIp = $request->header('X-Forwarded-For');
        $realIp = $realIp ? explode(',', $realIp)[0] : $request->ip();
        $realIp = trim($realIp);

        if (in_array($realIp, ['::1', '0:0:0:0:0:0:0:1'], true)) {
            $realIp = '127.0.0.1';
        }

        $clientSentAt = null;
        if (!empty($validated['client_sent_at'])) {
            try {
                $clientSentAt = Carbon::parse($validated['client_sent_at']);
            } catch (\Throwable $error) {
                $clientSentAt = null;
            }
        }

        $now = Carbon::now();
        $presence = DB::table('user_presence')->where('user_id', $user->id)->first();

        $presenceData = [
            'email' => $user->email,
            'name' => $user->name,
            'ip_address' => $realIp,
            'user_agent' => $request->header('User-Agent'),
            'current_path' => $validated['current_path'] ?? null,
            'last_seen_at' => $now,
            'last_client_sent_at' => $clientSentAt,
            'updated_at' => $now,
        ];

        if ($presence) {
            $presenceData['heartbeat_count'] = DB::raw('heartbeat_count + 1');
            DB::table('user_presence')->where('user_id', $user->id)->update($presenceData);
        } else {
            $presenceData['user_id'] = $user->id;
            $presenceData['heartbeat_count'] = 1;
            $presenceData['created_at'] = $now;
            DB::table('user_presence')->insert($presenceData);
        }

        return response()->json([
            'ok' => true,
            'recorded' => true,
            'user_id' => $user->id,
            'email' => $user->email,
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
            ->join('users', 'user_presence.user_id', '=', 'users.id')
            ->where('user_presence.last_seen_at', '>=', $cutoff)
            ->where(function ($query) {
                $query->whereNull('users.status')
                    ->orWhereNotIn('users.status', ['BANNED', 'DELETED']);
            })
            ->where('users.email', '<>', 'member@jsonapi.com')
            ->select(
                'user_presence.user_id',
                'user_presence.email',
                'user_presence.name',
                'user_presence.current_path',
                'user_presence.ip_address',
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
