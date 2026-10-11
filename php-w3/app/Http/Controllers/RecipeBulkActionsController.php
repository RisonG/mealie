<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * mealie/routes/recipe/bulk_actions.py — APIRouter(prefix="/bulk-actions") included with
 * prefix="/recipes". The export routes on the same controller are round 7.
 *
 * All four handlers have no return statement, so each answers a bare null with 200. There is a
 * "# TODO Should these actions return some success response?" at :36 acknowledging it.
 */
class RecipeBulkActionsController extends Controller
{
    /** :37 */
    public function tag(Request $request): JsonResponse
    {
        return $this->guard($request);
    }

    /** :41 */
    public function settings(Request $request): JsonResponse
    {
        return $this->guard($request);
    }

    /** :45 */
    public function categorize(Request $request): JsonResponse
    {
        return $this->guard($request);
    }

    /** :49 — PermissionDenied from RecipeService becomes a 403 ErrorResponse. */
    public function destroy(Request $request): JsonResponse
    {
        return $this->guard($request);
    }

    /** Every one of the four bodies requires `recipes`, so an empty object is a 422. */
    private function guard(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        if (! isset($body['recipes']) || ! is_array($body['recipes'])) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'recipes'), 'msg': 'Field required', 'input': {}}");
        }

        return Errors::detail(501, 'Bulk recipe actions are not implemented in this rewrite');
    }
}
