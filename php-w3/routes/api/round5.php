<?php

/**
 * Round 5 — households/shopping and households/mealplans.
 *
 * Route order is load-bearing here, and mealie/routes/households/__init__.py:26-28 says so for the
 * meal-plan case: /households/mealplans/rules would be swallowed by /households/mealplans/{item_id}
 * if the rules router came second. The same applies to /mealplans/today, /mealplans/random and
 * /shopping/items/create-bulk.
 */

use App\Http\Controllers\MealplansController;
use App\Http\Controllers\ShoppingItemsController;
use App\Http\Controllers\ShoppingListsController;
use Illuminate\Support\Facades\Route;

Route::prefix('households')->middleware('mealie:user')->group(function () {
    // controller_shopping_lists.py:38 — item_router. /create-bulk before /{item_id}.
    Route::get('/shopping/items', [ShoppingItemsController::class, 'index']);
    Route::post('/shopping/items/create-bulk', [ShoppingItemsController::class, 'createBulk']);
    Route::post('/shopping/items', [ShoppingItemsController::class, 'store']);
    Route::put('/shopping/items', [ShoppingItemsController::class, 'updateBulk']);
    Route::delete('/shopping/items', [ShoppingItemsController::class, 'destroyMany']);
    Route::get('/shopping/items/{itemId}', [ShoppingItemsController::class, 'show']);
    Route::put('/shopping/items/{itemId}', [ShoppingItemsController::class, 'update']);
    Route::delete('/shopping/items/{itemId}', [ShoppingItemsController::class, 'destroy']);

    // controller_shopping_lists.py:156 — the lists router.
    Route::get('/shopping/lists', [ShoppingListsController::class, 'index']);
    Route::post('/shopping/lists', [ShoppingListsController::class, 'store']);
    Route::get('/shopping/lists/{itemId}', [ShoppingListsController::class, 'show']);
    Route::put('/shopping/lists/{itemId}', [ShoppingListsController::class, 'update']);
    Route::delete('/shopping/lists/{itemId}', [ShoppingListsController::class, 'destroy']);
    Route::put('/shopping/lists/{itemId}/label-settings', [ShoppingListsController::class, 'labelSettings']);
    Route::post('/shopping/lists/{itemId}/recipe', [ShoppingListsController::class, 'addRecipes']);
    Route::post('/shopping/lists/{itemId}/recipe/{recipeId}/delete', [ShoppingListsController::class, 'removeRecipe']);
    Route::post('/shopping/lists/{itemId}/recipe/{recipeId}', [ShoppingListsController::class, 'addRecipe']);

    // controller_mealplan_rules.py — declared BEFORE the meal-plan routes, as Python requires.
    Route::get('/mealplans/rules', [MealplansController::class, 'rulesIndex']);
    Route::post('/mealplans/rules', [MealplansController::class, 'rulesStore']);
    Route::get('/mealplans/rules/{itemId}', [MealplansController::class, 'rulesShow']);
    Route::put('/mealplans/rules/{itemId}', [MealplansController::class, 'rulesUpdate']);
    Route::delete('/mealplans/rules/{itemId}', [MealplansController::class, 'rulesDestroy']);

    // controller_mealplan.py — the literal paths first, then the int-keyed item routes.
    Route::get('/mealplans/today', [MealplansController::class, 'today']);
    Route::post('/mealplans/random', [MealplansController::class, 'random']);
    Route::get('/mealplans', [MealplansController::class, 'index']);
    Route::post('/mealplans', [MealplansController::class, 'store']);
    Route::get('/mealplans/{itemId}', [MealplansController::class, 'show']);
    Route::put('/mealplans/{itemId}', [MealplansController::class, 'update']);
    Route::delete('/mealplans/{itemId}', [MealplansController::class, 'destroy']);
});
