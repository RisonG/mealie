<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use App\Support\GroupSerializer;
use App\Support\Guid;
use App\Support\HouseholdSerializer;
use App\Support\Pagination;
use App\Support\UserSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** mealie/routes/households/controller_household_self_service.py */
class HouseholdsController extends Controller
{
    /** :26 — any member. */
    public function self(Request $request): JsonResponse
    {
        return new JsonResponse(HouseholdSerializer::householdInDb($this->household($request)));
    }

    /** :31 — HouseholdService.get_household_recipe; unknown slug is a bare-string 404. */
    public function selfRecipe(Request $request, string $recipeSlug): JsonResponse
    {
        $user = $request->attributes->get('mealie_user');

        $recipe = DB::connection('mealie')->table('recipes')
            ->where('group_id', $user->group_id)->where('slug', $recipeSlug)->first();

        if (! $recipe) {
            return Errors::detail(404, 'Recipe not found');
        }

        return new JsonResponse([
            'id' => Guid::dash($recipe->id),
            'slug' => $recipe->slug,
            'name' => $recipe->name,
        ]);
    }

    /**
     * :40 — can_manage, and the page query is narrowed by appending household_id to queryFilter
     * rather than by repo scoping. Returns the full UserOut, not a summary.
     */
    public function members(Request $request): JsonResponse
    {
        if ($denied = $this->canManage($request)) {
            return $denied;
        }

        $user = $request->attributes->get('mealie_user');
        $builder = DB::connection('mealie')->table('users')->where('household_id', $user->household_id);

        return new JsonResponse(Pagination::paginate(
            $builder,
            Pagination::query($request),
            fn ($u) => UserSerializer::out($u),
            '/households/members'
        ));
    }

    /** :51 — any member. */
    public function preferences(Request $request): JsonResponse
    {
        $prefs = DB::connection('mealie')->table('household_preferences')
            ->where('household_id', $request->attributes->get('mealie_user')->household_id)->first();

        return new JsonResponse(GroupSerializer::householdPreferences($prefs));
    }

    /**
     * :55 — the only route in this module gated on can_manage_household rather than can_manage.
     * For bob both are 0, so the two are indistinguishable here; they are not for a user who has
     * one and not the other.
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $user = $request->attributes->get('mealie_user');
        if (! (bool) $user->can_manage_household) {
            return Errors::forbidden();
        }

        $columns = [
            'privateHousehold' => 'private_household',
            'showAnnouncements' => 'show_announcements',
            'lockRecipeEditsFromOtherHouseholds' => 'lock_recipe_edits_from_other_households',
            'firstDayOfWeek' => 'first_day_of_week',
            'recipePublic' => 'recipe_public',
            'recipeShowNutrition' => 'recipe_show_nutrition',
            'recipeShowAssets' => 'recipe_show_assets',
            'recipeLandscapeView' => 'recipe_landscape_view',
            'recipeDisableComments' => 'recipe_disable_comments',
        ];

        $body = $request->json()->all();
        $update = [];
        foreach ($columns as $camel => $column) {
            if (array_key_exists($camel, $body)) {
                $update[$column] = $column === 'first_day_of_week' ? (int) $body[$camel] : (bool) $body[$camel];
            }
        }
        if ($update) {
            DB::connection('mealie')->table('household_preferences')
                ->where('household_id', $user->household_id)->update($update);
        }

        $prefs = DB::connection('mealie')->table('household_preferences')
            ->where('household_id', $user->household_id)->first();

        return new JsonResponse(GroupSerializer::householdPreferences($prefs));
    }

    /** :61 — can_manage, then four checks in order, all with bare string details. */
    public function setPermissions(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        $userId = $body['userId'] ?? $body['user_id'] ?? null;
        if (! is_string($userId)) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'userId'), 'msg': 'Field required', 'input': None}");
        }
        if (! Guid::isV4($userId)) {
            return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('body', 'userId'), 'msg': 'Input should be a valid UUID', 'input': '$userId'}");
        }

        if ($denied = $this->canManage($request)) {
            return $denied;
        }

        $caller = $request->attributes->get('mealie_user');
        $target = DB::connection('mealie')->table('users')->where('id', Guid::pack($userId))->first();

        if (! $target) {
            return Errors::detail(404, 'User not found');                               // :68
        }
        if ($target->group_id !== $caller->group_id) {
            return Errors::detail(403, 'User is not a member of this group');           // :71
        }
        if ($target->household_id !== $caller->household_id) {
            return Errors::detail(403, 'User is not a member of this household');       // :74
        }
        if ($target->id === $caller->id) {
            return Errors::detail(403, 'User is not allowed to change their own permissions');  // :77
        }

        DB::connection('mealie')->table('users')->where('id', $target->id)->update([
            'can_invite' => (bool) ($body['canInvite'] ?? false),
            'can_manage' => (bool) ($body['canManage'] ?? false),
            'can_manage_household' => (bool) ($body['canManageHousehold'] ?? false),
            'can_organize' => (bool) ($body['canOrganize'] ?? false),
        ]);

        $updated = DB::connection('mealie')->table('users')->where('id', $target->id)->first();

        return new JsonResponse(UserSerializer::out($updated));
    }

    /**
     * :86 — HouseholdStatistics. Recipes, categories, tags and tools are counted across the
     * group; users within the household.
     */
    public function statistics(Request $request): JsonResponse
    {
        $user = $request->attributes->get('mealie_user');
        $db = DB::connection('mealie');

        return new JsonResponse([
            'totalRecipes' => $db->table('recipes')->where('group_id', $user->group_id)->count(),
            'totalUsers' => $db->table('users')->where('household_id', $user->household_id)->count(),
            'totalCategories' => $db->table('categories')->where('group_id', $user->group_id)->count(),
            'totalTags' => $db->table('tags')->where('group_id', $user->group_id)->count(),
            'totalTools' => $db->table('tools')->where('group_id', $user->group_id)->count(),
        ]);
    }

    private function household(Request $request): object
    {
        return DB::connection('mealie')->table('households')
            ->where('id', $request->attributes->get('mealie_user')->household_id)->first();
    }

    private function canManage(Request $request): ?JsonResponse
    {
        return (bool) $request->attributes->get('mealie_user')->can_manage ? null : Errors::forbidden();
    }
}
