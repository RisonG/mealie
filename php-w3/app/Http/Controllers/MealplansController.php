<?php

namespace App\Http\Controllers;

use App\Support\Dates;
use App\Support\Errors;
use App\Support\Guid;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * mealie/routes/households/controller_mealplan.py and controller_mealplan_rules.py.
 *
 * Two things set this apart. The meal-plan item id is an **int** (group_meal_plans.id is an INTEGER
 * primary key) while the rules id is a UUID4, under the same path prefix. And GET /mealplans is the
 * one paginated endpoint in the API that never calls set_pagination_guides, so its next and
 * previous are always null.
 */
class MealplansController extends Controller
{
    /** controller_mealplan.py:81 — start_date/end_date fold into the query filter. No guides. */
    public function index(Request $request): JsonResponse
    {
        $builder = $this->scoped($request);

        if ($start = $request->query('start_date')) {
            $builder->where('date', '>=', $start);
        }
        if ($end = $request->query('end_date')) {
            $builder->where('date', '<=', $end);
        }

        $page = Pagination::paginate($builder, Pagination::query($request), fn ($m) => $this->entry($m), '');

        // :101 returns page_all directly, with no set_pagination_guides call.
        $page['next'] = null;
        $page['previous'] = null;

        return new JsonResponse($page);
    }

    /** :103 — 201 */
    public function store(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        if (! isset($body['date'])) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'date'), 'msg': 'Field required', 'input': None}");
        }

        return Errors::detail(501, 'Creating meal plan entries is not implemented in this rewrite');
    }

    /** :129 — a literal path before /{item_id}; returns a bare array. */
    public function today(Request $request): JsonResponse
    {
        $rows = $this->scoped($request)->whereDate('date', gmdate('Y-m-d'))
            ->get()->map(fn ($m) => $this->entry($m))->all();

        return new JsonResponse($rows);
    }

    /** :134 — selects a recipe by the household's meal plan rules. */
    public function random(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        if (! isset($body['date'])) {   // CreateRandomEntry.date has no default
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'date'), 'msg': 'Field required', 'input': {}}");
        }

        return Errors::detail(501, 'Random meal plan selection is not implemented in this rewrite');
    }

    /** :182 — item_id is an int. */
    public function show(Request $request, string $itemId): JsonResponse
    {
        if ($invalid = $this->requireInt($itemId)) {
            return $invalid;
        }

        $entry = $this->scoped($request)->where('id', (int) $itemId)->first();

        return $entry ? new JsonResponse($this->entry($entry)) : Errors::repoNotFound();
    }

    /** :186 */
    public function update(Request $request, string $itemId): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        if ($invalid = $this->requireInt($itemId)) {
            return $invalid;
        }

        // UpdatePlanEntry requires date and entryType, and that validation precedes the lookup.
        $body = $request->json()->all();
        if (! isset($body['date'])) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'date'), 'msg': 'Field required', 'input': {}}");
        }

        if (! $this->scoped($request)->where('id', (int) $itemId)->first()) {
            return Errors::repoNotFound();
        }

        return Errors::detail(501, 'Updating meal plan entries is not implemented in this rewrite');
    }

    /** :211 */
    public function destroy(Request $request, string $itemId): JsonResponse
    {
        if ($invalid = $this->requireInt($itemId)) {
            return $invalid;
        }

        if (! $this->scoped($request)->where('id', (int) $itemId)->first()) {
            return Errors::repoNotFound();
        }

        return Errors::detail(501, 'Deleting meal plan entries is not implemented in this rewrite');
    }

    // ===== rules: controller_mealplan_rules.py, UUID4 ids and real pagination guides =====

    /** rules :27 */
    public function rulesIndex(Request $request): JsonResponse
    {
        return new JsonResponse(Pagination::paginate(
            $this->rulesScoped($request),
            Pagination::query($request),
            fn ($r) => $this->rule($r),
            '/households/mealplans/rules'
        ));
    }

    /** rules :37 — 201 */
    public function rulesStore(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        return Errors::detail(501, 'Creating meal plan rules is not implemented in this rewrite');
    }

    /** rules :42 */
    public function rulesShow(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        $rule = $this->rulesScoped($request)->where('id', Guid::pack($itemId))->first();

        return $rule ? new JsonResponse($this->rule($rule)) : Errors::repoNotFound();
    }

    /** rules :46 */
    public function rulesUpdate(Request $request, string $itemId): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        if (! $this->rulesScoped($request)->where('id', Guid::pack($itemId))->first()) {
            return Errors::repoNotFound();
        }

        return Errors::detail(501, 'Updating meal plan rules is not implemented in this rewrite');
    }

    /** rules :50 */
    public function rulesDestroy(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        if (! $this->rulesScoped($request)->where('id', Guid::pack($itemId))->first()) {
            return Errors::repoNotFound();
        }

        return Errors::detail(501, 'Deleting meal plan rules is not implemented in this rewrite');
    }

    /**
     * group_meal_plans has no household_id column; the household comes from the owning user, which
     * is how the repository's household filter reaches it.
     */
    private function scoped(Request $request)
    {
        $user = $request->attributes->get('mealie_user');
        $householdUsers = DB::connection('mealie')->table('users')
            ->where('household_id', $user->household_id)->pluck('id');

        return DB::connection('mealie')->table('group_meal_plans')
            ->where('group_id', $user->group_id)->whereIn('user_id', $householdUsers);
    }

    private function rulesScoped(Request $request)
    {
        $user = $request->attributes->get('mealie_user');

        return DB::connection('mealie')->table('group_meal_plan_rules')
            ->where('group_id', $user->group_id)->where('household_id', $user->household_id);
    }

    private function entry(object $m): array
    {
        return [
            'date' => Dates::dateOut($m->date),
            'entryType' => $m->entry_type,
            'title' => $m->title,
            'text' => $m->text,
            'recipeId' => Guid::dash($m->recipe_id),
            'id' => (int) $m->id,
            'groupId' => Guid::dash($m->group_id),
            'userId' => Guid::dash($m->user_id),
            'householdId' => Guid::dash(DB::connection('mealie')->table('users')
                ->where('id', $m->user_id)->value('household_id')),
            'recipe' => null,
        ];
    }

    private function rule(object $r): array
    {
        return [
            'day' => $r->day,
            'entryType' => $r->entry_type,
            'queryFilterString' => $r->query_filter_string ?? '',
            'groupId' => Guid::dash($r->group_id),
            'householdId' => Guid::dash($r->household_id),
            'id' => Guid::dash($r->id),
            'queryFilter' => null,
        ];
    }

    private function requireInt(string $value): ?JsonResponse
    {
        return preg_match('/^-?\d+$/', $value) === 1 ? null
            : Errors::unprocessable("1 validation error: {'type': 'int_parsing', 'loc': ('path', 'item_id'), 'msg': 'Input should be a valid integer, unable to parse string as an integer', 'input': '$value'}");
    }

    private function uuidError(string $value): JsonResponse
    {
        return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('path', 'item_id'), 'msg': 'Input should be a valid UUID', 'input': '$value'}");
    }
}
