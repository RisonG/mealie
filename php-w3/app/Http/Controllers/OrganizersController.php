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
 * mealie/routes/organizers/ — categories, tags and tools under /api/organizers.
 *
 * The three are not alike. Categories and tags guard create, merge, update and delete with
 * checks.can_organize(); controller_tools.py has no checks. call at all, so the same caller who is
 * refused a category can create a tool.
 *
 * Their pagination guides are missing the /organizers prefix, because url_path_for runs on the
 * sub-router and the prefix is added by __init__.py:5 at include time. Verified and reproduced.
 */
class OrganizersController extends Controller
{
    // ===== categories =====

    /** controller_categories.py:39 */
    public function categories(Request $request): JsonResponse
    {
        return $this->page($request, 'categories', '/categories', true);
    }

    /** :51 — can_organize */
    public function categoryStore(Request $request): JsonResponse
    {
        return $this->guardedWrite($request, 'Creating categories');
    }

    /** :72 — categories with no recipes. Verified [] here. */
    public function categoriesEmpty(Request $request): JsonResponse
    {
        return new JsonResponse($this->emptyOrganizers($request, 'categories', 'recipes_to_categories', 'category_id'));
    }

    /** :77 — POST, where foods and units use PUT for the same idea. */
    public function categoryMerge(Request $request): JsonResponse
    {
        return $this->merge($request, 'categories', 'category');
    }

    /** :92 — CategorySummary: three keys, no groupId and no recipeCount. */
    public function category(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        $row = $this->scoped($request, 'categories')->where('id', Guid::pack($itemId))->first();

        return $row
            ? new JsonResponse(['id' => Guid::dash($row->id), 'slug' => $row->slug, 'name' => $row->name])
            : Errors::repoNotFound();
    }

    /** :99 — can_organize */
    public function categoryUpdate(Request $request, string $itemId): JsonResponse
    {
        return $this->guardedItemWrite($request, 'categories', $itemId, 'Updating categories');
    }

    /** :121 — can_organize; the handler has no return, so a successful delete answers null. */
    public function categoryDestroy(Request $request, string $itemId): JsonResponse
    {
        return $this->guardedItemWrite($request, 'categories', $itemId, 'Deleting categories');
    }

    /** :141 — embeds the recipe list, and groupId comes back null. Verified. */
    public function categoryBySlug(Request $request, string $slug): JsonResponse
    {
        $row = $this->scoped($request, 'categories')->where('slug', $slug)->first();
        if (! $row) {
            return Errors::repoNotFound();
        }

        return new JsonResponse([
            'name' => $row->name,
            'id' => Guid::dash($row->id),
            'groupId' => null,
            'slug' => $row->slug,
            'recipes' => $this->linkedRecipes($request, 'recipes_to_categories', 'category_id', $row->id),
        ]);
    }

    // ===== tags =====

    /** controller_tags.py:29 */
    public function tags(Request $request): JsonResponse
    {
        return $this->page($request, 'tags', '/tags', true);
    }

    /** :66 — can_organize */
    public function tagStore(Request $request): JsonResponse
    {
        return $this->guardedWrite($request, 'Creating tags');
    }

    /** :41 — verified [] here. */
    public function tagsEmpty(Request $request): JsonResponse
    {
        return new JsonResponse($this->emptyOrganizers($request, 'tags', 'recipes_to_tags', 'tag_id'));
    }

    /** :46 — POST */
    public function tagMerge(Request $request): JsonResponse
    {
        return $this->merge($request, 'tags', 'tag');
    }

    /** :61 — RecipeTagResponse: name, id, groupId, slug, recipes. */
    public function tag(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        $row = $this->scoped($request, 'tags')->where('id', Guid::pack($itemId))->first();

        return $row ? new JsonResponse($this->tagResponse($request, $row)) : Errors::repoNotFound();
    }

    /** :88 — can_organize */
    public function tagUpdate(Request $request, string $itemId): JsonResponse
    {
        return $this->guardedItemWrite($request, 'tags', $itemId, 'Updating tags');
    }

    /** :110 — can_organize */
    public function tagDestroy(Request $request, string $itemId): JsonResponse
    {
        return $this->guardedItemWrite($request, 'tags', $itemId, 'Deleting tags');
    }

    /**
     * :133 — an unknown slug is a **500**, not a 404.
     *
     * This handler returns repo.get_one(...) straight to FastAPI, which then fails to validate
     * None against RecipeTagResponse. controller_categories.py:141 goes through mixins.get_one and
     * raises a proper 404 instead, so the two sibling controllers differ. Verified against the
     * oracle: categories/slug/<unknown> is 404, tags/slug/<unknown> is 500.
     */
    public function tagBySlug(Request $request, string $slug)
    {
        $row = $this->scoped($request, 'tags')->where('slug', $slug)->first();

        return $row ? new JsonResponse($this->tagResponse($request, $row)) : Errors::serverError();
    }

    // ===== tools: no permission check anywhere on this controller =====

    /** controller_tools.py:27 */
    public function tools(Request $request): JsonResponse
    {
        return $this->page($request, 'tools', '/tools', false);
    }

    /** :38 — 201. Reachable for a caller without can_organize, unlike categories and tags. */
    public function toolStore(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        if (! isset($body['name']) || ! is_string($body['name'])) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'name'), 'msg': 'Field required', 'input': {}}");
        }

        return Errors::detail(501, 'Creating tools is not implemented in this rewrite');
    }

    /** :43 */
    public function tool(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        $row = $this->scoped($request, 'tools')->where('id', Guid::pack($itemId))->first();

        return $row ? new JsonResponse($this->toolRow($row)) : Errors::repoNotFound();
    }

    /** :47 */
    public function toolUpdate(Request $request, string $itemId): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        if (! $this->scoped($request, 'tools')->where('id', Guid::pack($itemId))->first()) {
            return Errors::repoNotFound();
        }

        return Errors::detail(501, 'Updating tools is not implemented in this rewrite');
    }

    /** :52 */
    public function toolDestroy(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        if (! $this->scoped($request, 'tools')->where('id', Guid::pack($itemId))->first()) {
            return Errors::repoNotFound();
        }

        return Errors::detail(501, 'Deleting tools is not implemented in this rewrite');
    }

    /** :56 — same as tags: repo.get_one returned directly, so an unknown slug is a 500. */
    public function toolBySlug(Request $request, string $slug)
    {
        $row = $this->scoped($request, 'tools')->where('slug', $slug)->first();

        return $row ? new JsonResponse($this->toolRow($row)) : Errors::serverError();
    }

    // ===== helpers =====

    private function scoped(Request $request, string $table)
    {
        return DB::connection('mealie')->table($table)
            ->where('group_id', $request->attributes->get('mealie_user')->group_id);
    }

    /** The list item carries groupId and recipeCount; the single-read shapes do not. */
    private function page(Request $request, string $table, string $route, bool $withCount): JsonResponse
    {
        $joinTable = ['categories' => 'recipes_to_categories', 'tags' => 'recipes_to_tags', 'tools' => 'recipes_to_tools'][$table];
        $column = ['categories' => 'category_id', 'tags' => 'tag_id', 'tools' => 'tool_id'][$table];

        return new JsonResponse(Pagination::paginate(
            $this->scoped($request, $table),
            Pagination::query($request),
            function ($row) use ($withCount, $joinTable, $column, $table) {
                $out = [
                    'id' => Guid::dash($row->id),
                    'groupId' => Guid::dash($row->group_id),
                    'name' => $row->name,
                    'slug' => $row->slug,
                ];
                if ($table === 'tools') {
                    $out['onHand'] = (bool) $row->on_hand;
                }
                if ($withCount) {
                    $out['recipeCount'] = DB::connection('mealie')->table($joinTable)
                        ->where($column, $row->id)->count();
                }

                return $out;
            },
            $route
        ));
    }

    private function tagResponse(Request $request, object $row): array
    {
        return [
            'name' => $row->name,
            'id' => Guid::dash($row->id),
            'groupId' => Guid::dash($row->group_id),
            'slug' => $row->slug,
            'recipes' => $this->linkedRecipes($request, 'recipes_to_tags', 'tag_id', $row->id),
        ];
    }

    private function toolRow(object $row): array
    {
        return [
            'id' => Guid::dash($row->id),
            'groupId' => Guid::dash($row->group_id),
            'name' => $row->name,
            'slug' => $row->slug,
            'onHand' => (bool) $row->on_hand,
        ];
    }

    private function linkedRecipes(Request $request, string $joinTable, string $column, string $organizerId): array
    {
        $ids = DB::connection('mealie')->table($joinTable)->where($column, $organizerId)->pluck('recipe_id');

        return DB::connection('mealie')->table('recipes')
            ->where('group_id', $request->attributes->get('mealie_user')->group_id)
            ->whereIn('id', $ids)->orderBy('created_at', 'desc')->get()
            ->map(fn ($r) => RecipeSerializer::summary($r))->all();
    }

    private function emptyOrganizers(Request $request, string $table, string $joinTable, string $column): array
    {
        $used = DB::connection('mealie')->table($joinTable)->distinct()->pluck($column);

        return $this->scoped($request, $table)->whereNotIn('id', $used)->get()
            ->map(fn ($r) => ['id' => Guid::dash($r->id), 'name' => $r->name, 'slug' => $r->slug])->all();
    }

    /** :79-90 — can_organize, then from_id == to_id, then each id, each with a bare string detail. */
    private function merge(Request $request, string $table, string $label): JsonResponse
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

        if ($denied = $this->canOrganize($request)) {
            return $denied;
        }

        if (Guid::pack((string) $body['fromId']) === Guid::pack((string) $body['toId'])) {
            return Errors::detail(400, 'from_id and to_id must be different');
        }

        foreach (['fromId' => 'from_id', 'toId' => 'to_id'] as $camel => $snake) {
            if (! $this->scoped($request, $table)->where('id', Guid::pack((string) $body[$camel]))->first()) {
                return Errors::detail(404, "$snake $label not found");
            }
        }

        return Errors::detail(501, 'Merging organizers is not implemented in this rewrite');
    }

    private function guardedWrite(Request $request, string $what): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        if (! isset($body['name']) || ! is_string($body['name'])) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'name'), 'msg': 'Field required', 'input': {}}");
        }

        return $this->canOrganize($request) ?? Errors::detail(501, "$what is not implemented in this rewrite");
    }

    private function guardedItemWrite(Request $request, string $table, string $itemId, string $what): JsonResponse
    {
        if ($request->getMethod() !== 'DELETE' && Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        if ($denied = $this->canOrganize($request)) {
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
