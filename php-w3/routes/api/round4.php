<?php

/**
 * Round 4 — households core: cookbooks, event notifications, recipe actions, self-service,
 * invitations and webhooks. Shopping lists and meal plans are round 5.
 *
 * Every router in mealie/routes/households/ carries its own /households/... prefix and
 * __init__.py:15 includes them without adding one. Every controller extends BaseUserController or
 * BaseCrudController, so all 35 routes need a user.
 *
 * Literal segments precede parameterised ones, as in round 3.
 */

use App\Http\Controllers\CookbooksController;
use App\Http\Controllers\HouseholdInvitationsController;
use App\Http\Controllers\HouseholdNotifiersController;
use App\Http\Controllers\HouseholdRecipeActionsController;
use App\Http\Controllers\HouseholdsController;
use App\Http\Controllers\HouseholdWebhooksController;
use Illuminate\Support\Facades\Route;

Route::prefix('households')->middleware('mealie:user')->group(function () {
    // controller_cookbooks.py
    Route::get('/cookbooks', [CookbooksController::class, 'index']);
    Route::post('/cookbooks', [CookbooksController::class, 'store']);
    Route::put('/cookbooks', [CookbooksController::class, 'updateMany']);
    Route::get('/cookbooks/{itemId}', [CookbooksController::class, 'show']);
    Route::put('/cookbooks/{itemId}', [CookbooksController::class, 'update']);
    Route::delete('/cookbooks/{itemId}', [CookbooksController::class, 'destroy']);

    // controller_group_notifications.py — prefix /households/events/notifications
    Route::get('/events/notifications', [HouseholdNotifiersController::class, 'index']);
    Route::post('/events/notifications', [HouseholdNotifiersController::class, 'store']);
    Route::get('/events/notifications/{itemId}', [HouseholdNotifiersController::class, 'show']);
    Route::put('/events/notifications/{itemId}', [HouseholdNotifiersController::class, 'update']);
    Route::delete('/events/notifications/{itemId}', [HouseholdNotifiersController::class, 'destroy']);
    Route::post('/events/notifications/{itemId}/test', [HouseholdNotifiersController::class, 'test']);

    // controller_group_recipe_actions.py
    Route::get('/recipe-actions', [HouseholdRecipeActionsController::class, 'index']);
    Route::post('/recipe-actions', [HouseholdRecipeActionsController::class, 'store']);
    Route::get('/recipe-actions/{itemId}', [HouseholdRecipeActionsController::class, 'show']);
    Route::put('/recipe-actions/{itemId}', [HouseholdRecipeActionsController::class, 'update']);
    Route::delete('/recipe-actions/{itemId}', [HouseholdRecipeActionsController::class, 'destroy']);
    Route::post('/recipe-actions/{itemId}/trigger/{recipeSlug}', [HouseholdRecipeActionsController::class, 'trigger']);

    // controller_invitations.py
    Route::get('/invitations', [HouseholdInvitationsController::class, 'index']);
    Route::post('/invitations', [HouseholdInvitationsController::class, 'store']);
    Route::post('/invitations/email', [HouseholdInvitationsController::class, 'email']);

    // controller_webhooks.py — /rerun before /{item_id}
    Route::get('/webhooks', [HouseholdWebhooksController::class, 'index']);
    Route::post('/webhooks', [HouseholdWebhooksController::class, 'store']);
    Route::post('/webhooks/rerun', [HouseholdWebhooksController::class, 'rerun']);
    Route::get('/webhooks/{itemId}', [HouseholdWebhooksController::class, 'show']);
    Route::post('/webhooks/{itemId}/test', [HouseholdWebhooksController::class, 'test']);
    Route::put('/webhooks/{itemId}', [HouseholdWebhooksController::class, 'update']);
    Route::delete('/webhooks/{itemId}', [HouseholdWebhooksController::class, 'destroy']);

    // controller_household_self_service.py — /self/recipes/{slug} before /self would also work,
    // but Laravel matches the longer literal first only if it is declared first.
    Route::get('/self', [HouseholdsController::class, 'self']);
    Route::get('/self/recipes/{recipeSlug}', [HouseholdsController::class, 'selfRecipe']);
    Route::get('/members', [HouseholdsController::class, 'members']);
    Route::get('/preferences', [HouseholdsController::class, 'preferences']);
    Route::put('/preferences', [HouseholdsController::class, 'updatePreferences']);
    Route::put('/permissions', [HouseholdsController::class, 'setPermissions']);
    Route::get('/statistics', [HouseholdsController::class, 'statistics']);
});
