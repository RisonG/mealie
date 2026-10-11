<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use App\Support\Guid;
use App\Support\Pagination;
use App\Support\ShoppingSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * mealie/routes/households/controller_shopping_lists.py:38 — the item router.
 *
 * Four of these eight return something other than the path shape implies: POST and PUT on a single
 * item answer with a collection, because they delegate to the bulk handlers, and both DELETEs
 * answer with a SuccessResponse rather than the item.
 */
class ShoppingItemsController extends Controller
{
    /**
     * :115 — note the pagination guide. The handler calls router.url_path_for("get_all") where
     * `router` is the *lists* router defined at :156, not item_router, so Python advertises
     * /households/shopping/lists here. That is an upstream defect; reproducing it is required for
     * parity and "fixing" it would differ from the oracle.
     */
    public function index(Request $request): JsonResponse
    {
        return new JsonResponse(Pagination::paginate(
            $this->scoped($request),
            Pagination::query($request),
            fn ($i) => ShoppingSerializer::item($i),
            '/households/shopping/lists'
        ));
    }

    /** :121 — 201; the body is a JSON array. */
    public function createBulk(Request $request): JsonResponse
    {
        if ($invalid = $this->requireArrayBody($request)) {
            return $invalid;
        }

        $items = json_decode($request->getContent(), true);
        if ($items === []) {
            // Nothing to create, so no service call is needed and the 201 is exact.
            return new JsonResponse(self::emptyCollection(), 201);
        }

        return Errors::detail(501, 'Creating shopping list items is not implemented in this rewrite');
    }

    /** :127 — 201; delegates to createBulk, so the response is a collection. */
    public function store(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        if (! isset($body['shoppingListId']) && ! isset($body['shopping_list_id'])) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'shoppingListId'), 'msg': 'Field required', 'input': {}}");
        }

        return Errors::detail(501, 'Creating shopping list items is not implemented in this rewrite');
    }

    /** :131 — the only route here that returns a single item. */
    public function show(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        $item = $this->find($request, $itemId);

        return $item ? new JsonResponse(ShoppingSerializer::item($item)) : Errors::repoNotFound();
    }

    /** :135 — bulk update; the body is a JSON array. */
    public function updateBulk(Request $request): JsonResponse
    {
        if ($invalid = $this->requireArrayBody($request)) {
            return $invalid;
        }

        $items = json_decode($request->getContent(), true);
        if ($items === []) {
            return new JsonResponse(self::emptyCollection());
        }

        return Errors::detail(501, 'Updating shopping list items is not implemented in this rewrite');
    }

    /** :141 — delegates to updateBulk, so this too answers with a collection. */
    public function update(Request $request, string $itemId): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        return Errors::detail(501, 'Updating shopping list items is not implemented in this rewrite');
    }

    /** :145 — ids come from a repeated query parameter, not a body. */
    public function destroyMany(Request $request): JsonResponse
    {
        $ids = $request->query('ids');
        if ($ids === null) {
            // Nothing to delete; SuccessResponse.respond() with no message.
            return new JsonResponse(['message' => '', 'error' => false]);
        }

        // The parameter is list[UUID4], so each value is validated before the handler runs.
        foreach ((array) $ids as $index => $id) {
            if (! Guid::isV4((string) $id)) {
                return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('query', 'ids', $index), 'msg': 'Input should be a valid UUID', 'input': '$id'}");
            }
        }

        return Errors::detail(501, 'Deleting shopping list items is not implemented in this rewrite');
    }

    /** :151 — delegates to destroyMany; answers SuccessResponse, not the deleted item. */
    public function destroy(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        return Errors::detail(501, 'Deleting shopping list items is not implemented in this rewrite');
    }

    /** Items hang off a list, which carries the group; there is no group_id column on the item. */
    private function scoped(Request $request)
    {
        $user = $request->attributes->get('mealie_user');
        $listIds = DB::connection('mealie')->table('shopping_lists')
            ->where('group_id', $user->group_id)->pluck('id');

        return DB::connection('mealie')->table('shopping_list_items')->whereIn('shopping_list_id', $listIds);
    }

    private function find(Request $request, string $itemId): ?object
    {
        return $this->scoped($request)->where('id', Guid::pack($itemId))->first();
    }

    private function requireArrayBody(Request $request): ?JsonResponse
    {
        $decoded = json_decode($request->getContent(), true);
        if (is_array($decoded) && (array_is_list($decoded) || $decoded === [])) {
            return null;
        }

        return Errors::unprocessable("1 validation error: {'type': 'list_type', 'loc': ('body',), 'msg': 'Input should be a valid list'}");
    }

    /** ShoppingListItemsCollectionOut with nothing in it. */
    private static function emptyCollection(): array
    {
        return ['createdItems' => [], 'updatedItems' => [], 'deletedItems' => []];
    }

    private function uuidError(string $value): JsonResponse
    {
        return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('path', 'item_id'), 'msg': 'Input should be a valid UUID', 'input': '$value'}");
    }
}
