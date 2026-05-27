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
    Route::post('/VMD-user-heartbeat', [CustomController::class, 'F0_VMD_user_heartbeat']);
    Route::post('/VMD-get-online-users', [CustomController::class, 'F0_VMD_get_online_users']);

    Route::match(['get', 'post'], '/VMD-get-user-permissions', [CustomController::class, 'VMD_get_user_permissions']);

    Route::match(['get', 'post'], '/VMD-get-login-history', [CustomController::class, 'F0_VMD_get_login_history']);
    Route::match(['get', 'post'], '/VMD-get-audit-history', [CustomController::class, 'F0_VMD_get_audit_history']);
    Route::match(['get', 'post'], '/VMD-get-notifications', [CustomController::class, 'F0_VMD_get_notifications']);
    Route::match(['get', 'post'], '/VMD-get-documentation', [CustomController::class, 'F0_VMD_get_documentation']);
    Route::match(['get', 'post'], '/VMD-get-staff-game-intakes', [CustomController::class, 'F0_VMD_get_staff_game_intakes']);
    Route::match(['get', 'post'], '/VMD-get-class-intake-management-data', [CustomController::class, 'F0_VMD_get_class_intake_management_data']);
    Route::post('/VMD-get-class-intake-roster', [CustomController::class, 'F0_VMD_get_class_intake_roster']);
    Route::post('/VMD-save-staff-intake-assignments', [CustomController::class, 'F0_VMD_save_staff_intake_assignments']);
    Route::post('/VMD-create-notification', [CustomController::class, 'F0_VMD_create_notification']);
    Route::post('/VMD-mark-notifications-read', [CustomController::class, 'F0_VMD_mark_notifications_read']);
    Route::post('/VMD-clear-notifications', [CustomController::class, 'F0_VMD_clear_notifications']);
    Route::match(['get', 'post'], '/VMD-updateUser', [CustomController::class, 'F0_VMD_updateUser']);
    Route::post('/VMD-update-game-user-basic-info', [CustomController::class, 'F0_VMD_update_game_user_basic_info']);
    Route::post('/VMD-update-game-user-password', [CustomController::class, 'F0_VMD_update_game_user_password']);
    Route::post('/VMD-ban-game-user', [CustomController::class, 'F0_VMD_ban_game_user']);
    Route::post('/VMD-unban-game-user', [CustomController::class, 'F0_VMD_unban_game_user']);
    Route::post('/VMD-delete-game-user', [CustomController::class, 'F0_VMD_delete_game_user']);
    Route::match(['get', 'post'], '/VMD-ban-user', [CustomController::class, 'F0_VMD_ban_user']);
    Route::post('/VMD-unbanUser', [CustomController::class, 'F0_VMD_unbanUser']);
    Route::match(['get', 'post'], '/VMD-delete-user', [CustomController::class, 'F0_VMD_delete_user']);
    Route::post('/VMD-get-dashboard-settings', [CustomController::class, 'F0_VMD_get_dashboard_settings']);
    Route::post('/VMD-update-dashboard-settings', [CustomController::class, 'F0_VMD_update_dashboard_settings']);
    Route::post('/VMD-get-intake-game-settings', [CustomController::class, 'F0_VMD_get_intake_game_settings']);
    Route::post('/VMD-save-intake-game-settings', [CustomController::class, 'F0_VMD_save_intake_game_settings']);
    Route::post('/VMD-get-user-table-baselines', [CustomController::class, 'F0_VMD_get_user_table_baselines']);
    Route::post('/VMD-get-baseline-management-data', [CustomController::class, 'F0_VMD_get_baseline_management_data']);
    Route::post('/VMD-capture-user-table-baseline', [CustomController::class, 'F0_VMD_capture_user_table_baseline']);
    Route::post('/VMD-restore-user-table-baseline', [CustomController::class, 'F0_VMD_restore_user_table_baseline']);
    Route::post('/VMD-delete-user-table-baseline', [CustomController::class, 'F0_VMD_delete_user_table_baseline']);
    Route::post('/VMD-capture-game-user-baseline', [CustomController::class, 'F0_VMD_capture_game_user_baseline']);
    Route::post('/VMD-restore-game-user-baseline', [CustomController::class, 'F0_VMD_restore_game_user_baseline']);
    Route::post('/VMD-delete-game-user-baseline', [CustomController::class, 'F0_VMD_delete_game_user_baseline']);
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
    Route::post('uploads/game-users/{id}/profile-image', [UserController::class, 'uploadGameUserProfileImage']);

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
