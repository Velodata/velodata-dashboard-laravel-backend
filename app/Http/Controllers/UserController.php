<?php

namespace App\Http\Controllers;

use App\Models\GameUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;



class UserController extends Controller
{
    private function getAuditIpAddress(Request $request): string
    {
        $clientIpv4 = $request->input('vmd_ip_address_v4')
            ?: $request->input('data.attributes.vmd_ip_address_v4');
        if ($clientIpv4 && filter_var($clientIpv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $clientIpv4;
        }

        $clientIpv6 = $request->input('vmd_ip_address_v6')
            ?: $request->input('data.attributes.vmd_ip_address_v6');
        if ($clientIpv6 && filter_var($clientIpv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $clientIpv6;
        }

        $headerIp = $request->header('CF-Connecting-IP')
            ?: $request->header('X-Real-IP')
            ?: $request->header('X-Forwarded-For');

        if ($headerIp) {
            foreach (explode(',', $headerIp) as $candidate) {
                $candidate = trim($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return $candidate;
                }
            }

            foreach (explode(',', $headerIp) as $candidate) {
                $candidate = trim($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }

        $requestIp = $request->ip();
        if ($requestIp === '::1' || $requestIp === '0:0:0:0:0:0:0:1') {
            return '127.0.0.1';
        }

        return $requestIp ?: 'Unknown';
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

    private function userManagementTimeoutResponse(?string $email)
    {
        if (! $email) {
            return null;
        }

        $gameUser = GameUser::where('email', $email)->first();
        if (! $gameUser || ! $gameUser->action_locked_until) {
            return null;
        }

        $lockedUntil = \Carbon\Carbon::parse($gameUser->action_locked_until);
        if (! $lockedUntil->isFuture()) {
            return null;
        }

        $message = 'You are currently in a timeout period because you banned or deleted another user.';

        DB::table('user_notifications')->insert([
            'recipient_email' => $gameUser->email,
            'actor_email' => $gameUser->email,
            'type' => 'warning',
            'title' => 'User Management timeout',
            'message' => $message,
            'source' => 'user-management',
            'metadata' => json_encode([
                'action_locked_until' => $lockedUntil->toDateTimeString(),
                'reason' => $gameUser->action_locked_reason,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'outcome' => 'FAIL',
            'message' => $message,
            'action_locked_until' => $lockedUntil->toDateTimeString(),
        ], 423);
    }

    // Get all users
    public function index(Request $request)
    {
        $users = User::with('roles')->get(); // Eager load roles
        $gameIntakeId = $request->query('game_intake_id');
        $gameIntakeCode = $request->query('game_intake_code');
        $viewerEmail = $request->query('vmd_user_email');
        $viewer = $viewerEmail ? User::where('email', $viewerEmail)->first() : null;
        $viewerGameUser = (! $viewer && $viewerEmail) ? GameUser::where('email', $viewerEmail)->first() : null;
        $viewerCanAccessRequestedIntake = false;

        if (! $gameIntakeId && ! $gameIntakeCode) {
            $viewerCanAccessRequestedIntake = true;
        } elseif ($viewer) {
            $viewerCanAccessRequestedIntake = $this->staffCanAccessIntake($viewer, $gameIntakeId ? (int) $gameIntakeId : null, $gameIntakeCode);
        } elseif ($viewerGameUser) {
            $viewerCanAccessRequestedIntake =
                ($gameIntakeId && (int) $viewerGameUser->intake_id === (int) $gameIntakeId) ||
                ($gameIntakeCode && DB::table('game_intakes')
                    ->where('id', $viewerGameUser->intake_id)
                    ->where('code', $gameIntakeCode)
                    ->exists());
        }

        // Check if the include query parameter is set to roles
        $includeRoles = $request->query('include') === 'roles';

        // Initialize included array
        $included = [];

        // If roles are to be included, fetch the roles data
        if ($includeRoles) {
            $rolesResponse = app()->call('App\Http\Controllers\RoleController@index');
            $included = $rolesResponse->getData()->data; // Extract the roles data
        }

        $rolesByName = DB::table('roles')
            ->get()
            ->keyBy(fn ($role) => strtolower($role->name));
        $fallbackGameUserRole = $rolesByName->get('creator') ?: $rolesByName->get('member');

        $staffRows = $users->map(function ($user) {
            return [
                'type' => 'users',
                'id' => (string) $user->id,
                'attributes' => [
                    'identity_type' => 'staff',
                    'presence_key' => 'staff:' . $user->id,
                    'user_id' => $user->id,
                    'game_user_id' => null,
                    'name' => $user->name,
                    'email' => $user->email,
                    'profile_image' => $user->profile_image,
                    'role_name' => $user->roles->name ?? null,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                    'status' => $user->status,
                ],
                'relationships' => [
                    'roles' => [
                        'links' => [
                            'related' => url("api/v2/users/{$user->id}/roles"),
                            'self' => url("api/v2/users/{$user->id}/relationships/roles"),
                        ],
                        'data' => $user->roles ? [
                            [
                                'type' => 'roles',
                                'id' => (string) $user->roles->id,
                            ]
                        ] : [],
                    ],
                ],
                'links' => [
                    'self' => url("api/v2/users/{$user->id}"),
                ],
            ];
        });

        $gameRows = collect();

        if (($gameIntakeId || $gameIntakeCode) && $viewerCanAccessRequestedIntake) {
            $gameUsersQuery = GameUser::query()
                ->leftJoin('game_intakes', 'game_users.intake_id', '=', 'game_intakes.id')
                ->select(
                    'game_users.*',
                    'game_intakes.code as intake_code',
                    'game_intakes.name as intake_name'
                );

            if ($gameIntakeCode) {
                $gameUsersQuery->where('game_intakes.code', $gameIntakeCode);
            } elseif ($gameIntakeId) {
                $gameUsersQuery->where('game_users.intake_id', $gameIntakeId);
            }

            $gameRows = $gameUsersQuery
                ->orderBy('game_users.surname')
                ->orderBy('game_users.first_name')
                ->get()
                ->map(function ($gameUser) use ($rolesByName, $fallbackGameUserRole) {
                    $displayName = $gameUser->display_name
                        ?: trim(($gameUser->preferred_name ?: $gameUser->first_name) . ' ' . $gameUser->surname);
                    $gameUserRoleName = $gameUser->game_role ?: ($fallbackGameUserRole->name ?? 'Creator');
                    $gameUserRole = $rolesByName->get(strtolower($gameUserRoleName)) ?: $fallbackGameUserRole;
                    $gameUserRoleId = (string) ($gameUserRole->id ?? 3);

                    return [
                        'type' => 'game_users',
                        'id' => 'game-' . $gameUser->id,
                        'attributes' => [
                            'identity_type' => 'student',
                            'presence_key' => 'student:' . $gameUser->id,
                            'user_id' => null,
                            'game_user_id' => $gameUser->id,
                            'name' => $displayName,
                            'email' => $gameUser->email,
                            'profile_image' => $gameUser->profile_image,
                            'role_name' => $gameUserRoleName,
                            'game_intake_id' => $gameUser->intake_id,
                            'game_intake_code' => $gameUser->intake_code,
                            'game_intake_name' => $gameUser->intake_name,
                            'action_locked_until' => $gameUser->action_locked_until,
                            'action_locked_reason' => $gameUser->action_locked_reason,
                            'action_locked_by_game_user_id' => $gameUser->action_locked_by_game_user_id,
                            'created_at' => $gameUser->created_at,
                            'updated_at' => $gameUser->updated_at,
                            'status' => strtoupper($gameUser->game_status),
                        ],
                        'relationships' => [
                            'roles' => [
                                'links' => [
                                    'related' => null,
                                    'self' => null,
                                ],
                                'data' => [
                                    [
                                        'type' => 'roles',
                                        'id' => $gameUserRoleId,
                                    ],
                                ],
                            ],
                        ],
                        'links' => [
                            'self' => null,
                        ],
                    ];
                });
        }

        return response()->json([
            'jsonapi' => ['version' => '2.0'],
            'data' => $staffRows->concat($gameRows)->values(),
            // Include the included array if roles are requested
            'included' => $includeRoles ? $included : null,
        ]);
    }


    // Get a single user
    public function show($id)
    {
        // Fetch the user and include their role data directly
        $user = User::with('roles')->findOrFail($id);

        // Build the response with role_id and role_name directly in attributes
        return response()->json([
            'data' => [
                'type' => 'users',
                'id' => (string) $user->id,
                'attributes' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'profile_image' => $user->profile_image,
                    'role_id' => $user->roles->id ?? null,
                    'role_name' => $user->roles->name ?? null,
                    'created_at' => $user->created_at->toDateTimeString(),
                    'updated_at' => $user->updated_at->toDateTimeString(),
                ],
                'relationships' => [
                    'roles' => [
                        'links' => [
                            'related' => url("api/v2/users/{$user->id}/roles"),
                            'self' => url("api/v2/users/{$user->id}/relationships/roles"),
                        ],
                        'data' => $user->roles ? [
                            'type' => 'roles',
                            'id' => (string) $user->roles->id,
                        ] : null, // null if no role is associated
                    ],
                ],
                'links' => [
                    'self' => url("api/v2/users/{$user->id}"),
                ],
            ]
        ], 200);
    }

    public function store(Request $request)
    {
        // return;
        $data = $request->input('data');

        // Extract user attributes
        $attributes = $data['attributes'];
        if ($timeoutResponse = $this->userManagementTimeoutResponse($attributes['vmd_user_email'] ?? null)) {
            return $timeoutResponse;
        }

        $name = $attributes['name'];
        $email = $attributes['email'];
        $password = bcrypt($attributes['password']); // hash password securely
        $roleId = $data['relationships']['roles']['data'][0]['id'] ?? ($attributes['role_id'] ?? null);
        $createdByEmail = $attributes['vmd_user_email'] ?? null;
        $createdByName  = $attributes['vmd_user_name'] ?? null;
        $creatorGameUser = $createdByEmail ? GameUser::where('email', $createdByEmail)->first() : null;

        if ($creatorGameUser) {
            return $this->storeStudentCreatedGameUser($request, $attributes, $roleId, $creatorGameUser);
        }

        // Check if email already exists
        if (User::where('email', $email)->exists()) {
            return response()->json([
                // 'errors' => [[
                //     'status' => '409',
                //     'title' => 'Conflict',
                //     'detail' => 'A user with this email already exists.'
                // ]]
                'outcome' => 'FAIL',
                'email_exists' => 'false',
                'error' => 'Invalid credentials',
                'status' => '409',
                'detail' => 'A user with this email already exists.'
            ], 409);
        }

        // Create and save the new user
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'role_id' => $roleId,
        ]);

        $user->password = $password;
        $user->custno = $user->id + 100000;
        $user->save();

        // Determine real IP address
        $realIp = $this->getAuditIpAddress($request);

        // Handle optional audit trail for admin-created users
        $comments = $createdByEmail && $createdByName
            ? 'User created via New User function'
            : 'User self-registered via API';

        DB::table('user_audit_history')->insert([
            'custno' => $user->custno,
            'dteprfmd' => now(),
            'comments' => $comments,
            'clerk_id' => $createdByName ?? 'self',
            'created_by_email' => $createdByEmail ?? $email,
            'created_by_ip_address' => $realIp,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => $user], 201);
    }

    private function storeStudentCreatedGameUser(Request $request, array $attributes, $roleId, GameUser $creatorGameUser)
    {
        $name = trim((string) ($attributes['name'] ?? ''));
        $email = trim((string) ($attributes['email'] ?? ''));
        $password = (string) ($attributes['password'] ?? '');
        $createdByEmail = $attributes['vmd_user_email'] ?? $creatorGameUser->email;
        $createdByName = $attributes['vmd_user_name'] ?? $creatorGameUser->display_name;

        if ($email === '' || $name === '' || $password === '') {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Name, email, and password are required.',
            ], 422);
        }

        if (User::where('email', $email)->exists() || GameUser::where('email', $email)->exists()) {
            return response()->json([
                'outcome' => 'FAIL',
                'email_exists' => 'true',
                'error' => 'Invalid credentials',
                'status' => '409',
                'detail' => 'A user with this email already exists.'
            ], 409);
        }

        $roleName = DB::table('roles')->where('id', $roleId)->value('name') ?: 'Creator';
        if (strcasecmp((string) $roleName, 'Trainer') === 0) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Students cannot create Trainer accounts.',
            ], 422);
        }

        $nameParts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
        $firstName = $nameParts[0] ?? $name;
        $surname = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : 'Account';

        $metadata = [
            'source' => 'student_fake_account',
            'created_by_student_email' => $creatorGameUser->email,
            'created_by_game_user_id' => (int) $creatorGameUser->id,
            'created_by_student_name' => $creatorGameUser->display_name,
        ];

        $gameUser = GameUser::create([
            'intake_id' => $creatorGameUser->intake_id,
            'first_name' => $firstName,
            'surname' => $surname,
            'preferred_name' => $name,
            'display_name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'must_change_password' => false,
            'profile_image' => $attributes['profile_image'] ?? null,
            'game_role' => $roleName,
            'game_status' => 'active',
            'updated_by' => $createdByEmail,
            'metadata' => $metadata,
        ]);

        DB::table('user_audit_history')->insert([
            'custno' => 900000 + intval($gameUser->id),
            'dteprfmd' => now(),
            'comments' => 'Fake account created by Student via New User function',
            'clerk_id' => $createdByName,
            'created_by_email' => $createdByEmail,
            'created_by_ip_address' => $this->getAuditIpAddress($request),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'type' => 'game_users',
                'id' => $gameUser->id,
                'game_user_id' => $gameUser->id,
                'identity_type' => 'student',
                'name' => $gameUser->display_name,
                'email' => $gameUser->email,
                'role_id' => $roleId,
                'role_name' => $gameUser->game_role,
                'game_intake_id' => $gameUser->intake_id,
                'created_by_student_email' => $creatorGameUser->email,
            ],
        ], 201);
    }



    public function update(Request $request, $id)
    {
        $actorEmail = $request->input('data.attributes.vmd_user_email') ?: $request->input('vmd_user_email');
        if ($timeoutResponse = $this->userManagementTimeoutResponse($actorEmail)) {
            return $timeoutResponse;
        }

        // Fetch the real user IP from Cloudflare
        $realIp = $request->header('X-Forwarded-For');
        $realIp = $realIp ? explode(',', $realIp)[0] : $request->ip();

        // IPinfo API credentials
        $accessToken = '4af1c2308a696c';
        $apiUrl = "http://ipinfo.io/{$realIp}/json?token={$accessToken}";
        $pageContent = file_get_contents($apiUrl);
        if ($pageContent === false) {
            return response()->json(['message' => 'Failed to fetch geolocation data.'], 500);
        }
        $parsedJson = json_decode($pageContent);
        $country = $parsedJson->country ?? null;
        if ($country !== 'AU') {
            return response()->json(['message' => 'Unauthorized: Updates are only allowed from Australia.'], 403);
        }

        // Find the user by ID
        $user = User::findOrFail($id);

        // Validate the incoming request data
        $validatedData = $request->validate([
            'data.attributes.name' => 'required|string|max:255',
            'data.attributes.email' => 'required|email|max:255|unique:users,email,' . $id,
            'data.attributes.profile_image' => 'nullable|string|max:255',
            'data.attributes.role_id' => 'required|integer|exists:roles,id',
        ]);

        // Update user attributes
        $user->name = $validatedData['data']['attributes']['name'];
        $user->email = $validatedData['data']['attributes']['email'];
        $user->profile_image = $validatedData['data']['attributes']['profile_image'];
        $user->role_id = $validatedData['data']['attributes']['role_id']; // Set role_id

        // Save the user
        $user->save();

        // Return a success response
        return response()->json([
            'data' => [
                'id' => $user->id,
                'type' => 'users',
                'attributes' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'profile_image' => $user->profile_image,
                    'role_id' => $user->role_id, // Include role_id in the response
                    'country' => $parsedJson->country,
                    'region' => $parsedJson->region,
                    'City'        => $parsedJson->city,
                    'ZipCode'     => $parsedJson->postal,
                    'Timezone'    => $parsedJson->timezone,

                ],
            ],
        ], 200);
    }



    // Delete a user
    public function destroy($id)
    {
        // $user = User::findOrFail($id);
        // $user->delete();
        // return response()->json(null, 204);
    }




    public function uploadProfileImage(Request $request, $userId)
    {
        $request->validate([
            'attachment' => 'required|image|max:2048',
        ]);

        if ($timeoutResponse = $this->userManagementTimeoutResponse($request->input('vmd_user_email'))) {
            return $timeoutResponse;
        }

        $user = User::findOrFail($userId);
        if ((int) $user->id === 1 || strcasecmp((string) $user->email, 'admin@velodata.org') === 0) {
            return response()->json([
                'error' => [
                    'title' => 'Permission Denied',
                    'detail' => 'The System Admin avatar cannot be changed.',
                    'status' => 403,
                ]
            ], 403);
        }

        $path = "users/{$userId}/profile-image";

        try {
            // $filePath = Storage::put($path, $request->file('attachment'));
            $filePath = Storage::disk('public')->put($path, $request->file('attachment'));

            if (!$filePath) {
                return response()->json([
                    'error' => [
                        'title' => 'Upload Error',
                        'detail' => 'Failed to upload profile image',
                        'status' => 500,
                    ]
                ], 500);
            }

            $fileUrl = Storage::url($filePath);

            // Update user profile_image URL in the database
            $user->ensureCustno();
            // Use the current request host so local uploads resolve via laravel.localhost
            // and production uploads resolve via the production API host.
            $profileImageUrl = $request->getSchemeAndHttpHost() . $fileUrl;
            $user->profile_image = $profileImageUrl;
            $user->updated_by = $request->input('vmd_user_email') ?: $user->updated_by;
            $user->save();


            // ***************************************************************************************************************
            // IJV - 2025.04.02 - we can assume the two identifier parameters are included because you must be logged in.
            // 
            $realIp = $this->getAuditIpAddress($request);

            // Get vmd_user_email from the post data
            $createdByEmail = $request->input('vmd_user_email');
            $createdByName  = $request->input('vmd_user_name');

            // Log to user_audit_history
            DB::table('user_audit_history')->insert([
                'custno' => $user->custno,
                'dteprfmd' => now(),
                'comments' => $request->input('vmd_audit_reason') ?: 'Profile image updated',
                'clerk_id' => $createdByName,
                'created_by_email' => $createdByEmail,
                'created_by_ip_address' => $realIp,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            // **************************************************************************************************


            return response()->json([
                'jsonapi' => ['version' => '1.0'],
                'data' => [
                    'type' => 'profile',
                    'id' => $userId,
                    'attributes' => [
                        'profile_image' => $profileImageUrl,
                    ]
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'title' => 'Server Error',
                    'detail' => 'An error occurred while uploading the image.',
                    'status' => 500,
                    'meta' => ['exception' => $e->getMessage()],
                ]
            ], 500);
        }
    }

    public function uploadGameUserProfileImage(Request $request, $gameUserId)
    {
        $request->validate([
            'attachment' => 'required|image|max:2048',
            'vmd_user_email' => 'required|email',
        ]);

        $gameUser = GameUser::findOrFail($gameUserId);
        $actorEmail = $request->input('vmd_user_email');
        if ($timeoutResponse = $this->userManagementTimeoutResponse($actorEmail)) {
            return $timeoutResponse;
        }

        $isSelfUpdate = strcasecmp((string) $gameUser->email, (string) $actorEmail) === 0;

        if (! $isSelfUpdate && ! $this->canManageGameUsers($actorEmail)) {
            return response()->json([
                'outcome' => 'FAIL',
                'message' => 'Permission Denied: You can only update your own student profile unless you are Admin, Protector, or Trainer.',
            ], 403);
        }

        try {
            $path = "game-users/{$gameUserId}/profile-image";
            $filePath = Storage::disk('public')->put($path, $request->file('attachment'));

            if (!$filePath) {
                return response()->json([
                    'error' => [
                        'title' => 'Upload Error',
                        'detail' => 'Failed to upload game user profile image',
                        'status' => 500,
                    ]
                ], 500);
            }

            $fileUrl = Storage::url($filePath);
            $profileImageUrl = $request->getSchemeAndHttpHost() . $fileUrl;
            $gameUser->profile_image = $profileImageUrl;
            $gameUser->updated_by = $actorEmail;
            $gameUser->save();

            DB::table('user_audit_history')->insert([
                'custno' => 900000 + intval($gameUser->id),
                'dteprfmd' => now(),
                'comments' => $request->input('vmd_audit_reason') ?: 'Profile image updated',
                'clerk_id' => $request->input('vmd_user_name'),
                'created_by_email' => $actorEmail,
                'created_by_ip_address' => $this->getAuditIpAddress($request),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'jsonapi' => ['version' => '1.0'],
                'data' => [
                    'type' => 'game-user-profile',
                    'id' => $gameUserId,
                    'attributes' => [
                        'profile_image' => $profileImageUrl,
                    ]
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'title' => 'Server Error',
                    'detail' => 'An error occurred while uploading the game user profile image.',
                    'status' => 500,
                    'meta' => ['exception' => $e->getMessage()],
                ]
            ], 500);
        }
    }
}
