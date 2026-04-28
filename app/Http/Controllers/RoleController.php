<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\Permission;
use Illuminate\Database\QueryException;
// use Illuminate\Http\Request;

// namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Response;
use Carbon\Carbon;

class RoleController extends Controller
{
    // Get all roles
    // public function index()
    // {
    //     return Role::all();
    // }

    public function index(Request $request)
    {
        $roles = Role::all();

        $data = $roles->map(function ($role) {
            return [
                'type' => 'roles',
                'id' => (string) $role->id,
                'attributes' => [
                    'name' => $role->name,
                    'guard_name' => $role->guard_name, // Include guard_name
                    'created_at' => $role->created_at->toDateTimeString(),
                    'updated_at' => $role->updated_at->toDateTimeString(),
                ],
                'relationships' => [
                    'permissions' => [
                        'links' => [
                            'related' => route('roles.permissions', ['role' => $role->id]),
                            'self' => route('roles.relationships.permissions', ['role' => $role->id]),
                        ]
                    ],
                    'users' => [
                        'links' => [
                            'related' => route('roles.users', ['role' => $role->id]),
                            'self' => route('roles.relationships.users', ['role' => $role->id]),
                        ]
                    ]
                ],
                'links' => [
                    'self' => route('roles.show', ['role' => $role->id]),
                ],
            ];
        });

        return response()->json([
            'jsonapi' => [
                'version' => '2.0'
            ],
            'data' => $data,
        ], 200);
    }


    // Get a single role
    public function showDONTUSE($id)
    {
        // Fetch the role by ID
        $role = Role::findOrFail($id);

        // Structure the response according to JSON:API standards
        $response = [
            'jsonapi' => [
                'version' => '2.0'
            ],
            'links' => [
                'self' => route('roles.show', ['role' => $id])  // Correcting the parameter name to 'role'
            ],
            'data' => [
                'type' => 'roles',
                'id' => (string) $role->id,
                'attributes' => [
                    'name' => $role->name,
                    'created_at' => $role->created_at->toIso8601String(),
                    'updated_at' => $role->updated_at->toIso8601String()
                ],
                'relationships' => [
                    'permissions' => [
                        'links' => [
                            'related' => route('roles.permissions', ['role' => $id]),
                            'self' => route('roles.relationships.permissions', ['role' => $id])
                        ]
                    ],
                    'users' => [
                        'links' => [
                            'related' => route('roles.users', ['role' => $id]),
                            'self' => route('roles.relationships.users', ['role' => $id])
                        ]
                    ]
                ]
            ]
        ];

        // Return the formatted response as JSON
        return response()->json($response);
    }



    public function show($id)
    {
        $role = Role::findOrFail($id);

        // Get all permissions assigned to this role
        $permissions = $role->permissions()->get(['name']);

        // Convert to structured output: group by module + action
        $structured = [];

        foreach ($permissions as $permission) {
            if (preg_match('/(\w+)\s(\w+)/', $permission->name, $matches)) {
                $action = $matches[1]; // e.g. 'edit'
                $module = $matches[2]; // e.g. 'users'

                if (!isset($structured[$module])) {
                    $structured[$module] = ['view' => false, 'create' => false, 'edit' => false, 'delete' => false];
                }

                $structured[$module][$action] = true;
            }
        }

        return response()->json([
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $structured,
        ]);
    }


    // Create a new role
    // public function store(Request $request)
    // {
    //     $data = $request->validate([
    //         'name' => 'required|string|max:255',
    //         'description' => 'nullable|string|max:255',
    //     ]);

    //     $role = Role::create($data);
    //     return response()->json($role, 201);
    // }


    // IJV - 2025.06.19 - new flattened payload to simplify the JSON:API standards and work with a simpler REST API backend
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        try {
            $role = Role::create([
                'name' => $validated['name'],
                'guard_name' => 'api',
            ]);

            return response()->json([
                'message' => 'Role created successfully.',
                'data' => $role,
            ], 201);
        } catch (QueryException $e) {
            if ($e->errorInfo[1] == 1062) {
                return response()->json([
                    'message' => 'This role already exists.',
                ], 409);
            }

            // Let other DB exceptions bubble up
            throw $e;
        }
    }



    // Update an existing role
    public function updateDONTUSE(Request $request, $id)
    {
        $role = Role::findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
        ]);

        $role->update($data);
        return response()->json($role, 200);
    }

    // Update an existing role
    public function update(Request $request, $id)
    {
        $data = $request->input('data');
        $attributes = $data['attributes'] ?? [];

        // Find and update the role name
        $role = Role::findOrFail($id);
        $role->name = $attributes['name'];
        $role->save();

        // Process permissions
        $permissionsInput = $attributes['permissions'] ?? [];
        $flattenedPermissionNames = [];

        foreach ($permissionsInput as $module => $actions) {
            foreach ($actions as $action => $allowed) {
                if ($allowed) {
                    $flattenedPermissionNames[] = "{$action} {$module}";
                }
            }
        }

        // Fetch permission IDs based on names
        $permissionIds = Permission::whereIn('name', $flattenedPermissionNames)->pluck('id')->toArray();

        // Sync to role_has_permissions table (clears old and inserts new)
        DB::table('role_has_permissions')
            ->where('role_id', $role->id)
            ->delete();

        $insertRows = array_map(fn($permissionId) => [
            'role_id' => $role->id,
            'permission_id' => $permissionId
        ], $permissionIds);

        DB::table('role_has_permissions')->insert($insertRows);

        return response()->json([
            'message' => 'Role updated successfully',
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $flattenedPermissionNames
            ]
        ], 200);
    }


    // Delete a role
    public function destroy($id)
    {
        $role = Role::findOrFail($id);
        $role->delete();
        return response()->json(null, 204);
    }
}
