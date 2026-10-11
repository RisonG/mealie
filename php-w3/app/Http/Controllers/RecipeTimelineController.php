<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use App\Support\Guid;
use App\Support\Pagination;
use App\Support\RecipeSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * mealie/routes/recipe/timeline_events.py — UserAPIRouter(prefix="/timeline/events"), included with
 * prefix="/recipes" by __init__.py:14.
 *
 * That split is why the pagination guide is wrong: url_path_for runs on this router and only knows
 * "/timeline/events", so the advertised path is missing "/recipes". Verified against the oracle and
 * reproduced here rather than corrected.
 */
class RecipeTimelineController extends Controller
{
    /** :47 */
    public function index(Request $request): JsonResponse
    {
        return new JsonResponse(Pagination::paginate(
            $this->scoped($request),
            Pagination::query($request),
            fn ($e) => RecipeSerializer::timelineEvent($e),
            '/timeline/events'
        ));
    }

    /** :61 — 201; an unknown recipeId is a bare-string 404. */
    public function store(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        foreach (['recipeId', 'subject'] as $field) {
            if (! isset($body[$field])) {
                return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', '$field'), 'msg': 'Field required', 'input': {}}");
            }
        }

        $recipe = DB::connection('mealie')->table('recipes')
            ->where('group_id', $request->attributes->get('mealie_user')->group_id)
            ->where('id', Guid::pack((string) $body['recipeId']))->first();

        if (! $recipe) {
            return Errors::detail(404, 'recipe not found');
        }

        return Errors::detail(501, 'Creating timeline events is not implemented in this rewrite');
    }

    /** :89 */
    public function show(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        $event = $this->find($request, $itemId);

        return $event ? new JsonResponse(RecipeSerializer::timelineEvent($event)) : Errors::repoNotFound();
    }

    /** :96 */
    public function update(Request $request, string $itemId): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        if (! $this->find($request, $itemId)) {
            return Errors::repoNotFound();
        }

        return Errors::detail(501, 'Updating timeline events is not implemented in this rewrite');
    }

    /** :117 — returns the deleted event. */
    public function destroy(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        if (! $this->find($request, $itemId)) {
            return Errors::repoNotFound();
        }

        return Errors::detail(501, 'Deleting timeline events is not implemented in this rewrite');
    }

    /** :147 — multipart image upload. */
    public function image(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        if (! $request->hasFile('image')) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'image'), 'msg': 'Field required', 'input': None}");
        }

        return Errors::detail(501, 'Timeline event images are not implemented in this rewrite');
    }

    /** Events hang off a recipe, which carries the group. */
    private function scoped(Request $request)
    {
        $recipeIds = DB::connection('mealie')->table('recipes')
            ->where('group_id', $request->attributes->get('mealie_user')->group_id)->pluck('id');

        return DB::connection('mealie')->table('recipe_timeline_events')->whereIn('recipe_id', $recipeIds);
    }

    private function find(Request $request, string $itemId): ?object
    {
        return $this->scoped($request)->where('id', Guid::pack($itemId))->first();
    }

    private function uuidError(string $value): JsonResponse
    {
        return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('path', 'item_id'), 'msg': 'Input should be a valid UUID', 'input': '$value'}");
    }
}
