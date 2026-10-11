<?php

/**
 * Round 9 — explore, media, parser and shared recipe tokens. Admin is in round9-admin.php.
 *
 * explore is anonymous (BasePublicGroupExploreController depends on get_public_group, not on a
 * user). media is anonymous and carries the full /api itself in Python, included directly at
 * mealie/app.py:149 — here the /api prefix comes from bootstrap/app.php, as for every other route.
 *
 * Literal segments precede parameterised ones: /recipes/suggestions before /recipes/{recipe_slug},
 * and /images/timeline/{id}/{file} before /images/{file_name}.
 */

use App\Http\Controllers\ExploreController;
use App\Http\Controllers\MediaParserSharedController;
use Illuminate\Support\Facades\Route;

// mealie/routes/explore/ — anonymous.
Route::prefix('explore/groups/{groupSlug}')->group(function () {
    Route::get('/foods', [ExploreController::class, 'foods']);
    Route::get('/foods/{itemId}', [ExploreController::class, 'food']);

    Route::get('/households', [ExploreController::class, 'households']);
    Route::get('/households/{slug}', [ExploreController::class, 'household']);

    Route::get('/organizers/categories', [ExploreController::class, 'categories']);
    Route::get('/organizers/categories/{itemId}', [ExploreController::class, 'category']);
    Route::get('/organizers/tags', [ExploreController::class, 'tags']);
    Route::get('/organizers/tags/{itemId}', [ExploreController::class, 'tag']);
    Route::get('/organizers/tools', [ExploreController::class, 'tools']);
    Route::get('/organizers/tools/{itemId}', [ExploreController::class, 'tool']);

    Route::get('/cookbooks', [ExploreController::class, 'cookbooks']);
    Route::get('/cookbooks/{itemId}', [ExploreController::class, 'cookbook']);

    Route::get('/recipes', [ExploreController::class, 'recipes']);
    Route::get('/recipes/suggestions', [ExploreController::class, 'suggestions']);
    Route::get('/recipes/{recipeSlug}', [ExploreController::class, 'recipe']);
});

// mealie/routes/media/ — anonymous.
Route::prefix('media')->group(function () {
    Route::get('/docker/validate.txt', [MediaParserSharedController::class, 'dockerValidate']);
    Route::get('/recipes/{recipeId}/images/timeline/{eventId}/{fileName}', [MediaParserSharedController::class, 'timelineImage']);
    Route::get('/recipes/{recipeId}/images/{fileName}', [MediaParserSharedController::class, 'recipeImage']);
    Route::get('/recipes/{recipeId}/assets/{fileName}', [MediaParserSharedController::class, 'recipeAsset']);
    Route::get('/users/{userId}/{fileName}', [MediaParserSharedController::class, 'userImage']);
});

Route::middleware('mealie:user')->group(function () {
    // mealie/routes/parser/ingredient_parser.py
    Route::post('/parser/ingredient', [MediaParserSharedController::class, 'parseIngredient']);
    Route::post('/parser/ingredients', [MediaParserSharedController::class, 'parseIngredients']);

    // mealie/routes/shared/ — the authenticated side of the share-token feature.
    Route::get('/shared/recipes', [MediaParserSharedController::class, 'tokens']);
    Route::post('/shared/recipes', [MediaParserSharedController::class, 'tokenStore']);
    Route::get('/shared/recipes/{itemId}', [MediaParserSharedController::class, 'token']);
    Route::delete('/shared/recipes/{itemId}', [MediaParserSharedController::class, 'tokenDestroy']);
});
