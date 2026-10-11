<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use App\Support\Guid;
use App\Support\RecipeSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * mealie/routes/recipe/shared_routes.py — the share-link feature.
 *
 * ANONYMOUS. shared_routes.py:17 is a plain APIRouter() and both handlers are module-level
 * functions, not controller methods, so neither inherits a user dependency. Verified: a request
 * with no Authorization header gets the 404 below rather than a 401.
 */
class RecipeSharedController extends Controller
{
    /**
     * :22 — token_id is UUID4, so a non-v4 value is a 422. An unknown *or expired* token is a 404;
     * an expired one is deleted first (:27-34), which is the only write on this route.
     */
    public function show(Request $request, string $tokenId): JsonResponse
    {
        if (! Guid::isV4($tokenId)) {
            return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('path', 'token_id'), 'msg': 'Input should be a valid UUID', 'input': '$tokenId'}");
        }

        $token = DB::connection('mealie')->table('recipe_share_tokens')->where('id', Guid::pack($tokenId))->first();

        // An expired token is treated as absent. Deleting it is not reproduced; the response is.
        if ($token && $token->expires_at !== null && strtotime((string) $token->expires_at.' UTC') <= time()) {
            $token = null;
        }

        if (! $token) {
            return new JsonResponse(
                ['detail' => ['message' => 'Token Not Found', 'error' => true, 'exception' => null]],
                404
            );
        }

        $recipe = DB::connection('mealie')->table('recipes')->where('id', $token->recipe_id)->first();

        return $recipe ? new JsonResponse(RecipeSerializer::full($recipe)) : new JsonResponse(
            ['detail' => ['message' => 'Token Not Found', 'error' => true, 'exception' => null]],
            404
        );
    }

    /** :42 — calls the handler above first, so it inherits the same 422 and 404. */
    public function zip(Request $request, string $tokenId): JsonResponse
    {
        $response = $this->show($request, $tokenId);
        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        return Errors::detail(501, 'Shared recipe zip download is not implemented in this rewrite');
    }
}
