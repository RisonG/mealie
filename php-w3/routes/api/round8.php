<?php

/**
 * Round 8 — organizers, foods and units, and the standalone comments collection.
 *
 * Three different mount points: organizers/__init__.py:5 adds /organizers; unit_and_foods adds
 * nothing, so its controllers' own prefixes put them at /api/foods and /api/units; comments sits
 * at /api/comments, which is a different collection from /api/recipes/{slug}/comments.
 *
 * Literal second segments precede /{item_id} everywhere, and note that the foods and units merges
 * are PUT on a literal path, so they must be declared before PUT /{item_id}.
 */

use App\Http\Controllers\CommentsController;
use App\Http\Controllers\FoodsUnitsController;
use App\Http\Controllers\OrganizersController;
use Illuminate\Support\Facades\Route;

Route::middleware('mealie:user')->group(function () {
    Route::prefix('organizers')->group(function () {
        // controller_categories.py — /empty, /merge and /slug/{x} before /{item_id}.
        Route::get('/categories', [OrganizersController::class, 'categories']);
        Route::post('/categories', [OrganizersController::class, 'categoryStore']);
        Route::get('/categories/empty', [OrganizersController::class, 'categoriesEmpty']);
        Route::post('/categories/merge', [OrganizersController::class, 'categoryMerge']);
        Route::get('/categories/slug/{slug}', [OrganizersController::class, 'categoryBySlug']);
        Route::get('/categories/{itemId}', [OrganizersController::class, 'category']);
        Route::put('/categories/{itemId}', [OrganizersController::class, 'categoryUpdate']);
        Route::delete('/categories/{itemId}', [OrganizersController::class, 'categoryDestroy']);

        // controller_tags.py
        Route::get('/tags', [OrganizersController::class, 'tags']);
        Route::post('/tags', [OrganizersController::class, 'tagStore']);
        Route::get('/tags/empty', [OrganizersController::class, 'tagsEmpty']);
        Route::post('/tags/merge', [OrganizersController::class, 'tagMerge']);
        Route::get('/tags/slug/{slug}', [OrganizersController::class, 'tagBySlug']);
        Route::get('/tags/{itemId}', [OrganizersController::class, 'tag']);
        Route::put('/tags/{itemId}', [OrganizersController::class, 'tagUpdate']);
        Route::delete('/tags/{itemId}', [OrganizersController::class, 'tagDestroy']);

        // controller_tools.py — no permission check on any of these.
        Route::get('/tools', [OrganizersController::class, 'tools']);
        Route::post('/tools', [OrganizersController::class, 'toolStore']);
        Route::get('/tools/slug/{slug}', [OrganizersController::class, 'toolBySlug']);
        Route::get('/tools/{itemId}', [OrganizersController::class, 'tool']);
        Route::put('/tools/{itemId}', [OrganizersController::class, 'toolUpdate']);
        Route::delete('/tools/{itemId}', [OrganizersController::class, 'toolDestroy']);
    });

    // unit_and_foods/foods.py — /merge is a PUT, before PUT /{item_id}.
    Route::get('/foods', [FoodsUnitsController::class, 'foods']);
    Route::post('/foods', [FoodsUnitsController::class, 'foodStore']);
    Route::put('/foods/merge', [FoodsUnitsController::class, 'foodMerge']);
    Route::get('/foods/{itemId}', [FoodsUnitsController::class, 'food']);
    Route::put('/foods/{itemId}', [FoodsUnitsController::class, 'foodUpdate']);
    Route::delete('/foods/{itemId}', [FoodsUnitsController::class, 'foodDestroy']);

    // unit_and_foods/units.py — same shape, but with no permission check.
    Route::get('/units', [FoodsUnitsController::class, 'units']);
    Route::post('/units', [FoodsUnitsController::class, 'unitStore']);
    Route::put('/units/merge', [FoodsUnitsController::class, 'unitMerge']);
    Route::get('/units/{itemId}', [FoodsUnitsController::class, 'unit']);
    Route::put('/units/{itemId}', [FoodsUnitsController::class, 'unitUpdate']);
    Route::delete('/units/{itemId}', [FoodsUnitsController::class, 'unitDestroy']);

    // comments/__init__.py
    Route::get('/comments', [CommentsController::class, 'index']);
    Route::post('/comments', [CommentsController::class, 'store']);
    Route::get('/comments/{itemId}', [CommentsController::class, 'show']);
    Route::put('/comments/{itemId}', [CommentsController::class, 'update']);
    Route::delete('/comments/{itemId}', [CommentsController::class, 'destroy']);
});
