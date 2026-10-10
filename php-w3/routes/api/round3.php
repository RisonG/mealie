<?php

/**
 * Round 3 — groups.
 *
 * Every router in mealie/routes/groups/ carries its own full prefix and __init__.py:13 includes
 * them without adding one, so these paths come straight off each APIRouter(prefix=...).
 *
 * Authentication comes from the controller class, not the router: four of those files use a plain
 * APIRouter but every controller extends BaseUserController, so all 27 routes need a user.
 *
 * Literal segments are declared before parameterised ones, because Laravel matches in order where
 * FastAPI prefers literals regardless.
 */

use App\Http\Controllers\GroupAiProvidersController;
use App\Http\Controllers\GroupLabelsController;
use App\Http\Controllers\GroupMigrationsController;
use App\Http\Controllers\GroupReportsController;
use App\Http\Controllers\GroupsController;
use App\Http\Controllers\GroupSeederController;
use Illuminate\Support\Facades\Route;

Route::prefix('groups')->middleware('mealie:user')->group(function () {
    // controller_group_ai_providers.py — /test before /{provider_id}.
    Route::prefix('ai-providers')->group(function () {
        Route::get('/settings', [GroupAiProvidersController::class, 'settings']);
        Route::put('/settings', [GroupAiProvidersController::class, 'updateSettings']);

        Route::post('/providers', [GroupAiProvidersController::class, 'store']);
        Route::post('/providers/test', [GroupAiProvidersController::class, 'test']);
        Route::post('/providers/{providerId}/test', [GroupAiProvidersController::class, 'testSaved']);
        Route::get('/providers/{providerId}', [GroupAiProvidersController::class, 'show']);
        Route::put('/providers/{providerId}', [GroupAiProvidersController::class, 'update']);
        Route::delete('/providers/{providerId}', [GroupAiProvidersController::class, 'destroy']);
    });

    // controller_group_households.py
    Route::get('/households', [GroupsController::class, 'households']);
    Route::get('/households/{slug}', [GroupsController::class, 'household']);

    // controller_labels.py
    Route::get('/labels', [GroupLabelsController::class, 'index']);
    Route::post('/labels', [GroupLabelsController::class, 'store']);
    Route::get('/labels/{itemId}', [GroupLabelsController::class, 'show']);
    Route::put('/labels/{itemId}', [GroupLabelsController::class, 'update']);
    Route::delete('/labels/{itemId}', [GroupLabelsController::class, 'destroy']);

    // controller_group_reports.py
    Route::get('/reports', [GroupReportsController::class, 'index']);
    Route::get('/reports/{itemId}', [GroupReportsController::class, 'show']);
    Route::delete('/reports/{itemId}', [GroupReportsController::class, 'destroy']);

    // controller_migrations.py
    Route::post('/migrations', [GroupMigrationsController::class, 'store']);

    // controller_seeder.py
    Route::post('/seeders/foods', [GroupSeederController::class, 'foods']);
    Route::post('/seeders/units', [GroupSeederController::class, 'units']);

    // controller_group_self_service.py — /members/{x} after /members.
    Route::get('/self', [GroupsController::class, 'self']);
    Route::get('/members', [GroupsController::class, 'members']);
    Route::get('/members/{usernameOrId}', [GroupsController::class, 'member']);
    Route::get('/preferences', [GroupsController::class, 'preferences']);
    Route::put('/preferences', [GroupsController::class, 'updatePreferences']);
    Route::get('/storage', [GroupsController::class, 'storage']);
});
