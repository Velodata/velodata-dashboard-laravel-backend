<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CustomController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\RestController;
use App\Http\Controllers\TaskController;


Route::prefix('v2')->group(function () {
    // --- Custom routes ---
    Route::match(['get', 'post'], '/VMD-get-user-data', [CustomController::class, 'F0_PFS_get_user_data']);
    Route::match(['get', 'post'], '/VMD-login-user', [CustomController::class, 'F0_VMD_login_user']);

    Route::match(['get', 'post'], '/VMD-get-user-permissions', [CustomController::class, 'VMD_get_user_permissions']);

    Route::match(['get', 'post'], '/VMD-get-login-history', [CustomController::class, 'F0_VMD_get_login_history']);
    Route::match(['get', 'post'], '/VMD-get-audit-history', [CustomController::class, 'F0_VMD_get_audit_history']);
    Route::match(['get', 'post'], '/VMD-updateUser', [CustomController::class, 'F0_VMD_updateUser']);
    Route::match(['get', 'post'], '/VMD-ban-user', [CustomController::class, 'F0_VMD_ban_user']);
    Route::post('/VMD-unbanUser', [CustomController::class, 'F0_VMD_unbanUser']);
    Route::match(['get', 'post'], '/VMD-delete-user', [CustomController::class, 'F0_VMD_delete_user']);
    Route::match(['get', 'post'], '/VMD-verify-2fa', [CustomController::class, 'F0_VMD_verify_2fa_code']);
    Route::match(['get', 'post'], '/VMD-resend-2fa', [CustomController::class, 'F0_VMD_resend_2fa']);




    // --- Categories ---
    Route::apiResource('categories', CategoryController::class);

    // --- Items ---
    Route::apiResource('items', ItemController::class);
    Route::post('uploads/items/{id}/image', [ItemController::class, 'uploadItemImage']);

    // --- Users ---
    Route::apiResource('users', UserController::class);
    Route::get('users/{user}/roles', [UserController::class, 'getRoles'])->name('users.roles');
    Route::get('users/{user}/relationships/roles', [UserController::class, 'rolesRelationship'])->name('users.relationships.roles');
    Route::post('uploads/users/{id}/profile-image', [UserController::class, 'uploadProfileImage']);

    // --- Permissions ---
    Route::apiResource('permissions', PermissionController::class);
    Route::get('/permissions/{permission}/roles', [PermissionController::class, 'roles'])->name('permissions.roles');

    // --- Roles ---
    Route::apiResource('roles', RoleController::class);
    Route::get('/roles/{role}/permissions', [RoleController::class, 'permissions'])->name('roles.permissions');
    Route::get('/roles/{role}/users', [RoleController::class, 'users'])->name('roles.users');
    Route::get('/roles/{role}/relationships/permissions', [RoleController::class, 'permissions'])->name('roles.relationships.permissions');
    Route::get('/roles/{role}/relationships/users', [RoleController::class, 'users'])->name('roles.relationships.users');

    // --- Tags ---
    Route::apiResource('tags', TagController::class);

    // -------------------------------------------------------------------
    // 🧑‍🏫 Teaching routes: Clean, beginner-friendly REST API for students
    // -------------------------------------------------------------------
    Route::prefix('teach')->group(function () {
        Route::get('users', [RestController::class, 'getAllUsers']);
        Route::get('users/{id}', [RestController::class, 'getUserById']);
        Route::post('users', [RestController::class, 'createNewUser']);
        Route::put('users/{id}', [RestController::class, 'updateUserById']);
        Route::patch('users/{id}', [RestController::class, 'updateUser']);
        Route::delete('users/{id}', [RestController::class, 'deleteUserById']);
        Route::post('users/{id}/upload-image', [RestController::class, 'uploadUserProfileImage']);
        Route::post('/randomise-users', [RestController::class, 'randomiseUsers']);
    });


    // ------------------------------------------------------------------------------------------
    // 🧑‍🏫 Teaching routes: Clean, beginner-friendly REST API for students regarding To Do Appp
    // ------------------------------------------------------------------------------------------

    Route::controller(TaskController::class)->group(function () {
        Route::get('tasks', 'index');
        Route::post('tasks', 'store');
        Route::patch('tasks/complete/{id}', 'markComplete');
        Route::patch('tasks/notComplete/{id}', 'markIncomplete');
        Route::put('tasks/{id}', 'update');
        Route::delete('tasks/{id}', 'destroy');
    });
});
