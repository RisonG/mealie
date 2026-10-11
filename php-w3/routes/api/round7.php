<?php

/**
 * Round 7 — recipe import, images, assets, exports, shared links and comments.
 *
 * Every literal second segment is declared before /{slug}: shared, exports, create, test-scrape-url
 * and bulk-actions. Within the bulk export group, /export/purge precedes
 * /export/{export_id}/download.
 *
 * /recipes/shared/{token_id} is anonymous and sits outside the mealie:user group, because
 * shared_routes.py uses a plain APIRouter and module-level handlers.
 */

use App\Http\Controllers\RecipeBulkActionsController;
use App\Http\Controllers\RecipeImportController;
use App\Http\Controllers\RecipeMediaController;
use App\Http\Controllers\RecipeSharedController;
use Illuminate\Support\Facades\Route;

// shared_routes.py — no auth.
Route::prefix('recipes')->group(function () {
    Route::get('/shared/{tokenId}/zip', [RecipeSharedController::class, 'zip']);
    Route::get('/shared/{tokenId}', [RecipeSharedController::class, 'show']);
});

Route::prefix('recipes')->middleware('mealie:user')->group(function () {
    // exports.py — /exports is a literal, distinct from /{slug}/exports.
    Route::get('/exports', [RecipeMediaController::class, 'formats']);

    // recipe_crud_routes.py — scraping and import.
    Route::post('/test-scrape-url', [RecipeImportController::class, 'testScrapeUrl']);
    Route::post('/create/html-or-json/stream', [RecipeImportController::class, 'createFromHtmlOrJsonStream']);
    Route::post('/create/html-or-json', [RecipeImportController::class, 'createFromHtmlOrJson']);
    Route::post('/create/url/stream', [RecipeImportController::class, 'createFromUrlStream']);
    Route::post('/create/url/bulk', [RecipeImportController::class, 'createFromUrlBulk']);
    Route::post('/create/url', [RecipeImportController::class, 'createFromUrl']);
    Route::post('/create/ai/stream', [RecipeImportController::class, 'createFromAiStream']);
    Route::post('/create/ai', [RecipeImportController::class, 'createFromAi']);
    Route::post('/create/zip', [RecipeImportController::class, 'createFromZip']);
    Route::post('/create/image', [RecipeImportController::class, 'createFromImage']);

    // bulk_actions.py — the export half; /purge before /{export_id}/download.
    Route::post('/bulk-actions/export', [RecipeBulkActionsController::class, 'exportStart']);
    Route::get('/bulk-actions/export', [RecipeBulkActionsController::class, 'exportList']);
    Route::delete('/bulk-actions/export/purge', [RecipeBulkActionsController::class, 'exportPurge']);
    Route::get('/bulk-actions/export/{exportId}/download', [RecipeBulkActionsController::class, 'exportDownload']);

    // Images and assets, then the per-recipe export and comments.
    Route::post('/{slug}/image', [RecipeMediaController::class, 'scrapeImage']);
    Route::put('/{slug}/image', [RecipeMediaController::class, 'updateImage']);
    Route::delete('/{slug}/image', [RecipeMediaController::class, 'deleteImage']);
    Route::post('/{slug}/assets/url', [RecipeMediaController::class, 'assetFromUrl']);
    Route::post('/{slug}/assets', [RecipeMediaController::class, 'uploadAsset']);
    Route::get('/{slug}/exports', [RecipeMediaController::class, 'exportRecipe']);
    Route::get('/{slug}/comments', [RecipeMediaController::class, 'comments']);
});
