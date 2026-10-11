<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use App\Support\Guid;
use App\Support\Pagination;
use App\Support\ShoppingSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** mealie/routes/households/controller_shopping_lists.py:156 — the lists router. */
class ShoppingListsController extends Controller
{
    /** :176 — the summary shape, with no listItems. */
    public function index(Request $request): JsonResponse
    {
        return new JsonResponse(Pagination::paginate(
            $this->scoped($request),
            Pagination::query($request),
            fn ($l) => ShoppingSerializer::listSummary($l),
            '/households/shopping/lists'
        ));
    }

    /** :186 — 201 */
    public function store(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        if (! isset($body['name']) || ! is_string($body['name'])) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'name'), 'msg': 'Field required', 'input': None}");
        }

        return Errors::detail(501, 'Creating shopping lists is not implemented in this rewrite');
    }

    /** :200 — the full shape, with listItems inserted after id. */
    public function show(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        $list = $this->find($request, $itemId);

        return $list ? new JsonResponse(ShoppingSerializer::listOut($list)) : Errors::repoNotFound();
    }

    /** :204 */
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

        return Errors::detail(501, 'Updating shopping lists is not implemented in this rewrite');
    }

    /** :217 — returns the deleted list. */
    public function destroy(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        if (! $this->find($request, $itemId)) {
            return Errors::repoNotFound();
        }

        return Errors::detail(501, 'Deleting shopping lists is not implemented in this rewrite');
    }

    /**
     * :234 — the body is a JSON array, and any element whose shoppingListId differs from the path
     * id is a 400 with a bare string detail. That check runs before the write, so it is implemented.
     */
    public function labelSettings(Request $request, string $itemId): JsonResponse
    {
        $decoded = json_decode($request->getContent(), true);
        if (! is_array($decoded) || (! array_is_list($decoded) && $decoded !== [])) {
            return Errors::unprocessable("1 validation error: {'type': 'list_type', 'loc': ('body',), 'msg': 'Input should be a valid list'}");
        }

        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        foreach ($decoded as $setting) {
            $listId = $setting['shoppingListId'] ?? $setting['shopping_list_id'] ?? null;
            if (Guid::pack((string) $listId) !== Guid::pack($itemId)) {
                $id = $setting['id'] ?? 'None';

                return Errors::detail(400, "object $id has an invalid shopping list id");
            }
        }

        return Errors::detail(501, 'Updating label settings is not implemented in this rewrite');
    }

    /** :256 — the body is a JSON array of ShoppingListAddRecipeParamsBulk. */
    public function addRecipes(Request $request, string $itemId): JsonResponse
    {
        $decoded = json_decode($request->getContent(), true);
        if (! is_array($decoded) || (! array_is_list($decoded) && $decoded !== [])) {
            return Errors::unprocessable("1 validation error: {'type': 'list_type', 'loc': ('body',), 'msg': 'Input should be a valid list'}");
        }

        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        return Errors::detail(501, 'Adding recipe ingredients to a list is not implemented in this rewrite');
    }

    /** :263 — deprecated; the body is optional and defaults to recipeIncrementQuantity 1. */
    public function addRecipe(Request $request, string $itemId, string $recipeId): JsonResponse
    {
        if ($invalid = $this->twoUuids($itemId, $recipeId)) {
            return $invalid;
        }

        return Errors::detail(501, 'Adding recipe ingredients to a list is not implemented in this rewrite');
    }

    /** :274 — a POST whose purpose is removal; the path ends in /delete. */
    public function removeRecipe(Request $request, string $itemId, string $recipeId): JsonResponse
    {
        if ($invalid = $this->twoUuids($itemId, $recipeId)) {
            return $invalid;
        }

        return Errors::detail(501, 'Removing recipe ingredients from a list is not implemented in this rewrite');
    }

    private function scoped(Request $request)
    {
        return DB::connection('mealie')->table('shopping_lists')
            ->where('group_id', $request->attributes->get('mealie_user')->group_id);
    }

    private function find(Request $request, string $itemId): ?object
    {
        return $this->scoped($request)->where('id', Guid::pack($itemId))->first();
    }

    private function twoUuids(string $itemId, string $recipeId): ?JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }
        if (! Guid::isV4($recipeId)) {
            return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('path', 'recipe_id'), 'msg': 'Input should be a valid UUID', 'input': '$recipeId'}");
        }

        return null;
    }

    private function uuidError(string $value): JsonResponse
    {
        return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('path', 'item_id'), 'msg': 'Input should be a valid UUID', 'input': '$value'}");
    }
}
