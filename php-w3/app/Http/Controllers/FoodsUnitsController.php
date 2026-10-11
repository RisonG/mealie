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
 * mealie/routes/unit_and_foods/ — mounted at /api/foods and /api/units, because
 * unit_and_foods/__init__.py:5 adds no prefix of its own.
 *
 * foods guards create, merge, update and delete with checks.can_organize(); units.py has no
 * checks. call at all. The two controllers look symmetric and are not.
 *
 * Both merge routes are PUT, where the category and tag merges are POST.
 *
 * The item shapes are the ones round 5 already builds for shopping items.
 */
class FoodsUnitsController extends Controller
{
    /** foods.py:38 */
    public function foods(Request $request): JsonResponse
    {
        return new JsonResponse(Pagination::paginate(
            $this->scoped($request, 'ingredient_foods'),
            Pagination::query($request),
            fn ($f) => ShoppingSerializer::food($f),
            '/foods'
        ));
    }

    /** foods.py:49 — can_organize */
    public function foodStore(Request $request): JsonResponse
    {
        return $this->requireName($request) ?? $this->canOrganize($request)
            ?? Errors::detail(501, 'Creating foods is not implemented in this rewrite');
    }

    /** foods.py:55 — PUT on a literal path, so it must precede PUT /foods/{item_id}. */
    public function foodMerge(Request $request): JsonResponse
    {
        return $this->merge($request, 'ingredient_foods', true);
    }

    /** foods.py:65 */
    public function food(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        $row = $this->scoped($request, 'ingredient_foods')->where('id', Guid::pack($itemId))->first();

        return $row ? new JsonResponse(ShoppingSerializer::food($row)) : Errors::repoNotFound();
    }

    /** foods.py:69 — can_organize */
    public function foodUpdate(Request $request, string $itemId): JsonResponse
    {
        return $this->itemWrite($request, 'ingredient_foods', $itemId, true, 'Updating foods');
    }

    /** foods.py:75 — can_organize */
    public function foodDestroy(Request $request, string $itemId): JsonResponse
    {
        return $this->itemWrite($request, 'ingredient_foods', $itemId, true, 'Deleting foods');
    }

    /** units.py:38 */
    public function units(Request $request): JsonResponse
    {
        return new JsonResponse(Pagination::paginate(
            $this->scoped($request, 'ingredient_units'),
            Pagination::query($request),
            fn ($u) => ShoppingSerializer::unit($u),
            '/units'
        ));
    }

    /** units.py:49 — no permission check, so this is reachable for a caller without can_organize. */
    public function unitStore(Request $request): JsonResponse
    {
        return $this->requireName($request) ?? Errors::detail(501, 'Creating units is not implemented in this rewrite');
    }

    /** units.py:54 — PUT, before PUT /units/{item_id}. */
    public function unitMerge(Request $request): JsonResponse
    {
        return $this->merge($request, 'ingredient_units', false);
    }

    /** units.py:63 */
    public function unit(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        $row = $this->scoped($request, 'ingredient_units')->where('id', Guid::pack($itemId))->first();

        return $row ? new JsonResponse(ShoppingSerializer::unit($row)) : Errors::repoNotFound();
    }

    /** units.py:67 — no guard. */
    public function unitUpdate(Request $request, string $itemId): JsonResponse
    {
        return $this->itemWrite($request, 'ingredient_units', $itemId, false, 'Updating units');
    }

    /** units.py:71 — no guard. */
    public function unitDestroy(Request $request, string $itemId): JsonResponse
    {
        return $this->itemWrite($request, 'ingredient_units', $itemId, false, 'Deleting units');
    }

    private function scoped(Request $request, string $table)
    {
        return DB::connection('mealie')->table($table)
            ->where('group_id', $request->attributes->get('mealie_user')->group_id);
    }

    private function requireName(Request $request): ?JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();

        return isset($body['name']) && is_string($body['name'])
            ? null
            : Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'name'), 'msg': 'Field required', 'input': {}}");
    }

    private function merge(Request $request, string $table, bool $guarded): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        foreach (['fromId', 'toId'] as $field) {
            if (! isset($body[$field])) {
                return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', '$field'), 'msg': 'Field required', 'input': {}}");
            }
        }

        if ($guarded && ($denied = $this->canOrganize($request))) {
            return $denied;
        }

        return Errors::detail(501, 'Merging is not implemented in this rewrite');
    }

    private function itemWrite(Request $request, string $table, string $itemId, bool $guarded, string $what): JsonResponse
    {
        if ($request->getMethod() !== 'DELETE' && Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        if ($guarded && ($denied = $this->canOrganize($request))) {
            return $denied;
        }

        if (! $this->scoped($request, $table)->where('id', Guid::pack($itemId))->first()) {
            return Errors::repoNotFound();
        }

        return Errors::detail(501, "$what is not implemented in this rewrite");
    }

    private function canOrganize(Request $request): ?JsonResponse
    {
        return (bool) $request->attributes->get('mealie_user')->can_organize ? null : Errors::forbidden();
    }

    private function uuidError(string $value): JsonResponse
    {
        return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('path', 'item_id'), 'msg': 'Input should be a valid UUID', 'input': '$value'}");
    }
}
