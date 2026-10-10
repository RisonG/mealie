<?php

/**
 * Round 2 — users.
 *
 * Prefixes are worked out per file, because mealie/routes/users/__init__.py:6-15 does not apply
 * them uniformly: api_tokens.py and crud.py carry "/users" in their own router, while
 * registration.py, forgot_password.py, images.py and ratings.py are included with it.
 *
 * Order matters here in a way it does not in Python: FastAPI's literal paths win over parameterised
 * ones regardless of declaration order, Laravel's do not. So /users/self, /users/password and
 * /users/api-tokens are declared before /users/{item_id}.
 */

use App\Http\Controllers\UserImageController;
use App\Http\Controllers\UserPasswordController;
use App\Http\Controllers\UserRatingsController;
use App\Http\Controllers\UserRegistrationController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\UserTokensController;
use Illuminate\Support\Facades\Route;

Route::prefix('users')->group(function () {
    // registration.py and forgot_password.py — anonymous.
    Route::post('/register', [UserRegistrationController::class, 'register']);
    Route::post('/forgot-password', [UserPasswordController::class, 'forgot']);
    Route::post('/reset-password', [UserPasswordController::class, 'reset']);

    Route::middleware('mealie:user')->group(function () {
        // crud.py — the literal paths first.
        Route::get('/self', [UsersController::class, 'self']);
        Route::get('/self/ratings', [UsersController::class, 'selfRatings']);
        Route::get('/self/ratings/{recipeId}', [UsersController::class, 'selfRatingForRecipe']);
        Route::get('/self/favorites', [UsersController::class, 'selfFavorites']);
        Route::put('/password', [UsersController::class, 'updatePassword']);

        // api_tokens.py
        Route::post('/api-tokens', [UserTokensController::class, 'create']);
        Route::delete('/api-tokens/{tokenId}', [UserTokensController::class, 'destroy']);

        // ratings.py
        Route::get('/{id}/ratings', [UsersController::class, 'userRatings']);
        Route::get('/{id}/favorites', [UsersController::class, 'userFavorites']);
        Route::post('/{id}/ratings/{slug}', [UserRatingsController::class, 'setRating']);
        Route::post('/{id}/favorites/{slug}', [UserRatingsController::class, 'addFavorite']);
        Route::delete('/{id}/favorites/{slug}', [UserRatingsController::class, 'removeFavorite']);

        // images.py
        Route::post('/{id}/image', [UserImageController::class, 'update']);

        // crud.py:65 — the catch-all, declared last.
        Route::put('/{itemId}', [UsersController::class, 'updateUser']);
    });
});
