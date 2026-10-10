<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use App\Support\Guid;
use App\Support\HouseholdSerializer;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** mealie/routes/households/controller_group_recipe_actions.py — authenticated, no permission check. */
class HouseholdRecipeActionsController extends Controller
{
    /** :41 */
    public function index(Request $request): JsonResponse
    {
        return new JsonResponse(Pagination::paginate(
            $this->scoped($request),
            Pagination::query($request),
            fn ($a) => HouseholdSerializer::recipeAction($a),
            '/households/recipe-actions'
        ));
    }

    /** :51 — 201 */
    public function store(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        $missing = [];
        foreach (['actionType', 'title', 'url'] as $field) {
            if (! isset($body[$field])) {
                $missing[] = $field;
            }
        }
        if ($missing) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', '{$missing[0]}'), 'msg': 'Field required', 'input': None}");
        }

        return Errors::detail(501, 'Creating recipe actions is not implemented in this rewrite');
    }

    /** :56 */
    public function show(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        $action = $this->find($request, $itemId);

        return $action ? new JsonResponse(HouseholdSerializer::recipeAction($action)) : Errors::repoNotFound();
    }

    /** :60 */
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

        return Errors::detail(501, 'Updating recipe actions is not implemented in this rewrite');
    }

    /** :64 — returns the deleted action. */
    public function destroy(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        if (! $this->find($request, $itemId)) {
            return Errors::repoNotFound();
        }

        return Errors::detail(501, 'Deleting recipe actions is not implemented in this rewrite');
    }

    /**
     * :71 — 202 on success. Order of checks at :75-96: unknown action → 404 ErrorResponse
     * "Not found."; an action whose type is not "post" → 400 ErrorResponse naming the type;
     * unknown recipe → 404. Only then is the outbound POST made, which is not implemented.
     */
    public function trigger(Request $request, string $itemId, string $recipeSlug): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        $action = $this->find($request, $itemId);
        if (! $action) {
            return Errors::repoNotFound();
        }

        if ($action->action_type !== 'post') {
            return new JsonResponse([
                'detail' => [
                    'message' => "Cannot trigger action type \"{$action->action_type}\".",
                    'error' => true,
                    'exception' => null,
                ],
            ], 400);
        }

        $recipe = DB::connection('mealie')->table('recipes')
            ->where('group_id', $request->attributes->get('mealie_user')->group_id)
            ->where('slug', $recipeSlug)->first();

        if (! $recipe) {
            return Errors::repoNotFound();
        }

        return Errors::detail(501, 'Triggering a recipe action is not implemented in this rewrite');
    }

    private function scoped(Request $request)
    {
        $user = $request->attributes->get('mealie_user');

        return DB::connection('mealie')->table('recipe_actions')
            ->where('group_id', $user->group_id)->where('household_id', $user->household_id);
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
