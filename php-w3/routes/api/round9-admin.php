<?php

/**
 * Round 9 — mealie/routes/admin/, all 38 routes.
 *
 * Paths copied from each sub-router's own prefix plus AdminAPIRouter(prefix="/admin") in
 * admin/__init__.py:15. Literal segments precede parameterised ones: /users/unlock and
 * /users/password-reset-token before /users/{item_id}, /backups/upload before /backups/{file_name},
 * /maintenance/storage and /maintenance/clean/* before nothing else on that prefix.
 *
 * The mealie:admin middleware is get_admin_user: 401 without a token, 403 for a non-admin, and it
 * runs before the body is parsed, matching FastAPI's router dependency.
 */

use App\Http\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware('mealie:admin')->group(function () {
    // admin_about.py
    Route::get('/about', [AdminController::class, 'about']);
    Route::get('/about/statistics', [AdminController::class, 'statistics']);
    Route::get('/about/check', [AdminController::class, 'check']);

    // admin_management_users.py — literals before /{item_id}.
    Route::get('/users', [AdminController::class, 'users']);
    Route::post('/users', [AdminController::class, 'userStore']);
    Route::post('/users/unlock', [AdminController::class, 'userUnlock']);
    Route::post('/users/password-reset-token', [AdminController::class, 'userResetToken']);
    Route::get('/users/{itemId}', [AdminController::class, 'user']);
    Route::put('/users/{itemId}', [AdminController::class, 'userUpdate']);
    Route::delete('/users/{itemId}', [AdminController::class, 'userDestroy']);

    // admin_management_households.py
    Route::get('/households', [AdminController::class, 'households']);
    Route::post('/households', [AdminController::class, 'householdStore']);
    Route::get('/households/{itemId}', [AdminController::class, 'household']);
    Route::put('/households/{itemId}', [AdminController::class, 'householdUpdate']);
    Route::delete('/households/{itemId}', [AdminController::class, 'householdDestroy']);

    // admin_management_ai_providers.py — prefix /groups/{group_id}/ai-providers, so these must be
    // declared before the plain /groups/{item_id} routes below.
    Route::post('/groups/{groupId}/ai-providers/providers', [AdminController::class, 'aiProviderStore']);
    Route::get('/groups/{groupId}/ai-providers/providers/{providerId}', [AdminController::class, 'aiProvider']);
    Route::put('/groups/{groupId}/ai-providers/providers/{providerId}', [AdminController::class, 'aiProviderUpdate']);
    Route::delete('/groups/{groupId}/ai-providers/providers/{providerId}', [AdminController::class, 'aiProviderDestroy']);

    // admin_management_groups.py
    Route::get('/groups', [AdminController::class, 'groups']);
    Route::post('/groups', [AdminController::class, 'groupStore']);
    Route::get('/groups/{itemId}', [AdminController::class, 'group']);
    Route::put('/groups/{itemId}', [AdminController::class, 'groupUpdate']);
    Route::delete('/groups/{itemId}', [AdminController::class, 'groupDestroy']);

    // admin_email.py
    Route::get('/email', [AdminController::class, 'email']);
    Route::post('/email', [AdminController::class, 'emailSend']);

    // admin_backups.py — /upload before /{file_name}.
    Route::get('/backups', [AdminController::class, 'backups']);
    Route::post('/backups', [AdminController::class, 'backupCreate']);
    Route::post('/backups/upload', [AdminController::class, 'backupUpload']);
    Route::post('/backups/{fileName}/restore', [AdminController::class, 'backupRestore']);
    Route::get('/backups/{fileName}', [AdminController::class, 'backup']);
    Route::delete('/backups/{fileName}', [AdminController::class, 'backupDestroy']);

    // admin_maintenance.py
    Route::get('/maintenance', [AdminController::class, 'maintenance']);
    Route::get('/maintenance/storage', [AdminController::class, 'maintenanceStorage']);
    Route::post('/maintenance/clean/images', [AdminController::class, 'cleanImages']);
    Route::post('/maintenance/clean/temp', [AdminController::class, 'cleanTemp']);
    Route::post('/maintenance/clean/recipe-folders', [AdminController::class, 'cleanRecipeFolders']);

    // admin_debug.py
    Route::post('/debug/openai/{providerId}', [AdminController::class, 'debugOpenai']);
});
