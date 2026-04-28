<?php

namespace App\Http\Controllers;

use App\Models\Permission; // Ensure you have a Permission model
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PermissionController extends Controller
{
    // Display a listing of the resource
    public function index()
    {
        $permissions = Permission::all();
        return response()->json(['data' => $permissions]);
    }

    // Store a newly created resource in storage
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'guard_name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $permission = Permission::create($request->all());
        return response()->json(['data' => $permission], 201);
    }

    // Display the specified resource
    public function show($id)
    {
        $permission = Permission::findOrFail($id);
        return response()->json(['data' => $permission]);
    }

    // Update the specified resource in storage
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'guard_name' => 'string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $permission = Permission::findOrFail($id);
        $permission->update($request->all());
        return response()->json(['data' => $permission]);
    }

    // Remove the specified resource from storage
    public function destroy($id)
    {
        $permission = Permission::findOrFail($id);
        $permission->delete();
        return response()->json(null, 204);
    }
}
