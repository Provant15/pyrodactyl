<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Controllers\Api\Admin;

/*
|--------------------------------------------------------------------------
| Admin API Routes
|--------------------------------------------------------------------------
|
| Endpoints for the React admin panel. Uses Sanctum SPA session auth
| with role-based permission checking per route.
|
*/

// Roles
Route::prefix('/roles')->group(function () {
    Route::get('/', [Admin\RoleController::class, 'index'])
        ->middleware('admin.permission:admin:roles.view');
    Route::get('/{role}', [Admin\RoleController::class, 'show'])
        ->middleware('admin.permission:admin:roles.view');
    Route::post('/', [Admin\RoleController::class, 'store'])
        ->middleware('admin.permission:admin:roles.manage');
    Route::patch('/{role}', [Admin\RoleController::class, 'update'])
        ->middleware('admin.permission:admin:roles.manage');
    Route::delete('/{role}', [Admin\RoleController::class, 'destroy'])
        ->middleware('admin.permission:admin:roles.manage');
});

// Plans
Route::prefix('/plans')->group(function () {
    Route::get('/', [Admin\PlanController::class, 'index'])
        ->middleware('admin.permission:admin:plans.view');
    Route::get('/{plan}', [Admin\PlanController::class, 'show'])
        ->middleware('admin.permission:admin:plans.view');
    Route::post('/', [Admin\PlanController::class, 'store'])
        ->middleware('admin.permission:admin:plans.manage');
    Route::patch('/{plan}', [Admin\PlanController::class, 'update'])
        ->middleware('admin.permission:admin:plans.manage');
    Route::delete('/{plan}', [Admin\PlanController::class, 'destroy'])
        ->middleware('admin.permission:admin:plans.manage');
});

// Permissions metadata (for role editor UI)
Route::get('/permissions', [Admin\RoleController::class, 'permissions'])
    ->middleware('admin.permission:admin:roles.view');
