<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use App\Support\GroupSerializer;
use App\Support\Guid;
use App\Support\HouseholdSerializer;
use App\Support\Pagination;
use App\Support\RecipeSerializer;
use App\Support\ShoppingSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * mealie/routes/explore/ — APIRouter(prefix="/explore/groups/{group_slug}").
 *
 * ANONYMOUS. The controllers extend BasePublicGroupExploreController and
 * BasePublicHouseholdExploreController (base_controllers.py:86, :106), whose dependency is
 * get_public_group rather than get_current_user. Verified: no token needed.
 *
 * get_public_group (dependencies.py:66-73) resolves the slug **or id** and raises 404
 * "group not found" when the group is missing *or private*. The two are indistinguishable from
 * outside, which is the point.
 */
class ExploreController extends Controller
{
    /** foods :19 */
    public function foods(Request $request, string $groupSlug): JsonResponse
    {
        return $this->paged($groupSlug, 'ingredient_foods', fn ($f) => ShoppingSerializer::food($f),
            "/explore/groups/$groupSlug/foods");
    }

    /** foods :34 */
    public function food(Request $request, string $groupSlug, string $itemId): JsonResponse
    {
        return $this->item($groupSlug, 'ingredient_foods', $itemId, fn ($f) => ShoppingSerializer::food($f));
    }

    /** households :19 — private households are excluded from the public view. */
    public function households(Request $request, string $groupSlug): JsonResponse
    {
        $group = $this->publicGroup($groupSlug);
        if (! $group) {
            return $this->groupNotFound();
        }

        $builder = DB::connection('mealie')->table('households')->where('group_id', $group->id)
            ->whereIn('id', $this->publicHouseholdIds($group->id));

        return new JsonResponse(Pagination::paginate($builder, Pagination::query($request),
            fn ($h) => GroupSerializer::householdSummary($h), "/explore/groups/$groupSlug/households"));
    }

    /** households :30 — by slug or id. */
    public function household(Request $request, string $groupSlug, string $slug): JsonResponse
    {
        $group = $this->publicGroup($groupSlug);
        if (! $group) {
            return $this->groupNotFound();
        }

        $query = DB::connection('mealie')->table('households')->where('group_id', $group->id)
            ->whereIn('id', $this->publicHouseholdIds($group->id));
        $packed = Guid::pack($slug);
        $row = $packed !== null ? $query->where('id', $packed)->first() : $query->where('slug', $slug)->first();

        return $row ? new JsonResponse(GroupSerializer::householdSummary($row))
            : Errors::detail(404, 'household not found');
    }

    /** organizers :24 / :39 */
    public function categories(Request $request, string $groupSlug): JsonResponse
    {
        return $this->organizers($request, $groupSlug, 'categories');
    }

    public function category(Request $request, string $groupSlug, string $itemId): JsonResponse
    {
        return $this->organizerItem($groupSlug, 'categories', $itemId);
    }

    /** organizers :54 / :69 */
    public function tags(Request $request, string $groupSlug): JsonResponse
    {
        return $this->organizers($request, $groupSlug, 'tags');
    }

    public function tag(Request $request, string $groupSlug, string $itemId): JsonResponse
    {
        return $this->organizerItem($groupSlug, 'tags', $itemId);
    }

    /** organizers :84 / :99 */
    public function tools(Request $request, string $groupSlug): JsonResponse
    {
        return $this->organizers($request, $groupSlug, 'tools');
    }

    public function tool(Request $request, string $groupSlug, string $itemId): JsonResponse
    {
        return $this->organizerItem($groupSlug, 'tools', $itemId);
    }

    /** cookbooks :22 — household-level, so only public households' cookbooks. */
    public function cookbooks(Request $request, string $groupSlug): JsonResponse
    {
        $group = $this->publicGroup($groupSlug);
        if (! $group) {
            return $this->groupNotFound();
        }

        $builder = DB::connection('mealie')->table('cookbooks')->where('group_id', $group->id)
            ->where('public', true)->whereIn('household_id', $this->publicHouseholdIds($group->id));

        return new JsonResponse(Pagination::paginate($builder, Pagination::query($request),
            fn ($c) => HouseholdSerializer::cookbook($c), "/explore/groups/$groupSlug/cookbooks"));
    }

    /** cookbooks :40 */
    public function cookbook(Request $request, string $groupSlug, string $itemId): JsonResponse
    {
        $group = $this->publicGroup($groupSlug);
        if (! $group) {
            return $this->groupNotFound();
        }

        $query = DB::connection('mealie')->table('cookbooks')->where('group_id', $group->id)->where('public', true);
        $packed = Guid::pack($itemId);
        $row = $packed !== null ? $query->where('id', $packed)->first() : $query->where('slug', $itemId)->first();

        return $row ? new JsonResponse(HouseholdSerializer::cookbook($row)) : Errors::repoNotFound();
    }

    /** recipes :31 — the orjson path, so datetimes are "+00:00" as in round 6. */
    public function recipes(Request $request, string $groupSlug): JsonResponse
    {
        $group = $this->publicGroup($groupSlug);
        if (! $group) {
            return $this->groupNotFound();
        }

        $builder = DB::connection('mealie')->table('recipes')->where('group_id', $group->id);

        return new JsonResponse(Pagination::paginate($builder, Pagination::query($request),
            fn ($r) => RecipeSerializer::summary($r, '+00:00'), "/explore/groups/$groupSlug/recipes"));
    }

    /** recipes :92 — a literal path, before /{recipe_slug}. */
    public function suggestions(Request $request, string $groupSlug): JsonResponse
    {
        $group = $this->publicGroup($groupSlug);
        if (! $group) {
            return $this->groupNotFound();
        }

        $limit = (int) ($request->query('limit') ?? 10);
        $rows = DB::connection('mealie')->table('recipes')->where('group_id', $group->id)
            ->orderBy('created_at', 'desc')->limit($limit)->get()
            ->map(fn ($r) => ['recipe' => RecipeSerializer::summary($r, '+00:00'), 'missingFoods' => [], 'missingTools' => []])
            ->all();

        return new JsonResponse(['items' => $rows]);
    }

    /** recipes :109 — the full Recipe. */
    public function recipe(Request $request, string $groupSlug, string $recipeSlug): JsonResponse
    {
        $group = $this->publicGroup($groupSlug);
        if (! $group) {
            return $this->groupNotFound();
        }

        $query = DB::connection('mealie')->table('recipes')->where('group_id', $group->id);
        $packed = Guid::pack($recipeSlug);
        $row = $packed !== null ? $query->where('id', $packed)->first() : $query->where('slug', $recipeSlug)->first();

        return $row ? new JsonResponse(RecipeSerializer::full($row)) : Errors::repoNotFound();
    }

    // ===== helpers =====

    /** dependencies.py:66-73 — by slug or id, and a private group is reported as missing. */
    private function publicGroup(string $slugOrId): ?object
    {
        $db = DB::connection('mealie');
        $packed = Guid::pack($slugOrId);
        $group = $packed !== null
            ? $db->table('groups')->where('id', $packed)->first()
            : $db->table('groups')->where('slug', $slugOrId)->first();

        if (! $group) {
            return null;
        }

        $prefs = $db->table('group_preferences')->where('group_id', $group->id)->first();

        return $prefs && ! (bool) $prefs->private_group ? $group : null;
    }

    private function publicHouseholdIds(string $groupId): array
    {
        return DB::connection('mealie')->table('households')
            ->join('household_preferences', 'household_preferences.household_id', '=', 'households.id')
            ->where('households.group_id', $groupId)
            ->where('household_preferences.private_household', false)
            ->pluck('households.id')->all();
    }

    private function groupNotFound(): JsonResponse
    {
        return Errors::detail(404, 'group not found');
    }

    private function paged(string $groupSlug, string $table, callable $mapper, string $route): JsonResponse
    {
        $group = $this->publicGroup($groupSlug);
        if (! $group) {
            return $this->groupNotFound();
        }

        $builder = DB::connection('mealie')->table($table)->where('group_id', $group->id);

        return new JsonResponse(Pagination::paginate($builder, Pagination::query(request()), $mapper, $route));
    }

    private function item(string $groupSlug, string $table, string $itemId, callable $mapper): JsonResponse
    {
        $group = $this->publicGroup($groupSlug);
        if (! $group) {
            return $this->groupNotFound();
        }

        if (! Guid::isV4($itemId)) {
            return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('path', 'item_id'), 'msg': 'Input should be a valid UUID', 'input': '$itemId'}");
        }

        $row = DB::connection('mealie')->table($table)->where('group_id', $group->id)
            ->where('id', Guid::pack($itemId))->first();

        return $row ? new JsonResponse($mapper($row)) : Errors::repoNotFound();
    }

    private function organizers(Request $request, string $groupSlug, string $table): JsonResponse
    {
        return $this->paged($groupSlug, $table, fn ($r) => [
            'id' => Guid::dash($r->id),
            'name' => $r->name,
            'slug' => $r->slug,
        ], "/explore/groups/$groupSlug/organizers/$table");
    }

    private function organizerItem(string $groupSlug, string $table, string $itemId): JsonResponse
    {
        return $this->item($groupSlug, $table, $itemId, fn ($r) => [
            'id' => Guid::dash($r->id),
            'name' => $r->name,
            'slug' => $r->slug,
        ]);
    }
}
