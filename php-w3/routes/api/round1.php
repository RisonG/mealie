<?php

/**
 * Round 1 — app, auth, validators, utils.
 *
 * Paths copied from mealie/routes/app/__init__.py, mealie/routes/auth/__init__.py,
 * mealie/routes/validators/__init__.py and mealie/routes/utility_routes.py. The /api prefix comes
 * from bootstrap/app.php.
 */

use App\Http\Controllers\AppAboutController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UtilsController;
use App\Http\Controllers\ValidatorsController;
use Illuminate\Support\Facades\Route;

// mealie/routes/app/app_about.py — anonymous, no auth dependency.
Route::prefix('app/about')->group(function () {
    Route::get('/', [AppAboutController::class, 'about']);
    Route::get('/startup-info', [AppAboutController::class, 'startupInfo']);
    Route::get('/theme', [AppAboutController::class, 'theme']);
});

// mealie/routes/auth/auth.py — public_router is anonymous, user_router carries get_current_user.
Route::prefix('auth')->group(function () {
    Route::post('/token', [AuthController::class, 'token']);
    Route::get('/oauth', [AuthController::class, 'oauth']);
    Route::get('/oauth/callback', [AuthController::class, 'oauthCallback']);
    Route::get('/oauth/native/config', [AuthController::class, 'nativeConfig']);
    Route::post('/oauth/native/token', [AuthController::class, 'nativeToken']);

    Route::middleware('mealie:user')->group(function () {
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

// mealie/routes/validators/validators.py — all five anonymous.
Route::prefix('validators')->group(function () {
    Route::get('/user/name', [ValidatorsController::class, 'userName']);
    Route::get('/user/email', [ValidatorsController::class, 'userEmail']);
    Route::get('/group', [ValidatorsController::class, 'group']);
    Route::get('/household', [ValidatorsController::class, 'household']);
    Route::get('/recipe', [ValidatorsController::class, 'recipe']);
});

// mealie/routes/utility_routes.py — the file token is the authorization, no user dependency.
Route::get('utils/download', [UtilsController::class, 'download']);
