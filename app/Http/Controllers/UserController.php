<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;



class UserController extends Controller
{
    // Get all users
    public function index(Request $request)
    {
        $users = User::with('roles')->get(); // Eager load roles

        // Check if the include query parameter is set to roles
        $includeRoles = $request->query('include') === 'roles';

        // Initialize included array
        $included = [];

        // If roles are to be included, fetch the roles data
        if ($includeRoles) {
            $rolesResponse = app()->call('App\Http\Controllers\RoleController@index');
            $included = $rolesResponse->getData()->data; // Extract the roles data
        }

        return response()->json([
            'jsonapi' => ['version' => '2.0'],
            'data' => $users->map(function ($user) {
                return [
                    'type' => 'users',
                    'id' => (string) $user->id,
                    'attributes' => [
                        'name' => $user->name,
                        'email' => $user->email,
                        'profile_image' => $user->profile_image,
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
            }),
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
        $name = $attributes['name'];
        $email = $attributes['email'];
        $password = bcrypt($attributes['password']); // hash password securely

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

        // Extract role ID from the relationships
        $roleId = $data['relationships']['roles']['data'][0]['id'] ?? null;

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
        $realIp = $request->header('X-Forwarded-For');
        $realIp = $realIp ? explode(',', $realIp)[0] : $request->ip();

        // Handle optional audit trail for admin-created users
        $createdByEmail = $attributes['vmd_user_email'] ?? null;
        $createdByName  = $attributes['vmd_user_name'] ?? null;

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



    public function update(Request $request, $id)
    {

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
            $user = User::findOrFail($userId);
            $user->ensureCustno();
            // Use the current request host so local uploads resolve via laravel.localhost
            // and production uploads resolve via the production API host.
            $profileImageUrl = $request->getSchemeAndHttpHost() . $fileUrl;
            $user->profile_image = $profileImageUrl;
            $user->save();


            // ***************************************************************************************************************
            // IJV - 2025.04.02 - we can assume the two identifier parameters are included because you must be logged in.
            // 
            $realIp = $request->header('X-Forwarded-For');
            $realIp = $realIp ? explode(',', $realIp)[0] : $request->ip();

            // Get vmd_user_email from the post data
            $createdByEmail = $request->input('vmd_user_email');
            $createdByName  = $request->input('vmd_user_name');

            // Log to user_audit_history
            DB::table('user_audit_history')->insert([
                'custno' => $user->custno,
                'dteprfmd' => now(),
                'comments' => 'User created via New User function',
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
}
