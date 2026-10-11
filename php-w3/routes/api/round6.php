<?php

/**
 * Round 6 — recipe CRUD, timeline events and bulk actions.
 *
 * __init__.py:5-14 adds prefix="/recipes" to comments, bulk_actions, shared_routes and
 * timeline_events, but not to exports or recipe_crud_routes, which carry it themselves.
 *
 * Literal segments precede /{slug}: suggestions, timeline, bulk-actions.
 */

use App\Http\Controllers\RecipeBulkActionsController;
use App\Http\Controllers\RecipesController;
use App\Http\Controllers\RecipeTimelineController;
use Illuminate\Support\Facades\Route;

Route::prefix('recipes')->middleware('mealie:user')->group(function () {
    // timeline_events.py — before /{slug}
    Route::get('/timeline/events', [RecipeTimelineController::class, 'index']);
    Route::post('/timeline/events', [RecipeTimelineController::class, 'store']);
    Route::get('/timeline/events/{itemId}', [RecipeTimelineController::class, 'show']);
    Route::put('/timeline/events/{itemId}', [RecipeTimelineController::class, 'update']);
    Route::delete('/timeline/events/{itemId}', [RecipeTimelineController::class, 'destroy']);
    Route::put('/timeline/events/{itemId}/image', [RecipeTimelineController::class, 'image']);

    // bulk_actions.py — the four mutating actions; exports are round 7.
    Route::post('/bulk-actions/tag', [RecipeBulkActionsController::class, 'tag']);
    Route::post('/bulk-actions/settings', [RecipeBulkActionsController::class, 'settings']);
    Route::post('/bulk-actions/categorize', [RecipeBulkActionsController::class, 'categorize']);
    Route::post('/bulk-actions/delete', [RecipeBulkActionsController::class, 'destroy']);

    // recipe_crud_routes.py
    Route::get('/suggestions', [RecipesController::class, 'suggestions']);
    Route::get('/', [RecipesController::class, 'index']);
    Route::post('/', [RecipesController::class, 'store']);
    Route::put('/', [RecipesController::class, 'updateMany']);
    Route::patch('/', [RecipesController::class, 'patchMany']);
    Route::post('/{slug}/duplicate', [RecipesController::class, 'duplicate']);
    Route::patch('/{slug}/last-made', [RecipesController::class, 'lastMade']);
    Route::get('/{slug}', [RecipesController::class, 'show']);
    Route::put('/{slug}', [RecipesController::class, 'update']);
    Route::patch('/{slug}', [RecipesController::class, 'patch']);
    Route::delete('/{slug}', [RecipesController::class, 'destroy']);
});
