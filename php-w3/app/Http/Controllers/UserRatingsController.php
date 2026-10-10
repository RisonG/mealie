<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use App\Support\Guid;
use App\Support\UserPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** mealie/routes/users/ratings.py — the mutating half. */
class UserRatingsController extends Controller
{
    /** ratings.py:56 */
    public function setRating(Request $request, string $id, string $slug)
    {
        $body = $request->json()->all();

        return $this->upsert($request, $id, $slug, [
            'rating' => array_key_exists('rating', $body) ? $body['rating'] : null,
            'is_favorite' => $body['isFavorite'] ?? $body['is_favorite'] ?? null,
        ]);
    }

    /** ratings.py:80 — set_rating with is_favorite true and no rating. */
    public function addFavorite(Request $request, string $id, string $slug)
    {
        return $this->upsert($request, $id, $slug, ['rating' => null, 'is_favorite' => true]);
    }

    /** ratings.py:85 — set_rating with is_favorite false. Updates the row; it does not delete it. */
    public function removeFavorite(Request $request, string $id, string $slug)
    {
        return $this->upsert($request, $id, $slug, ['rating' => null, 'is_favorite' => false]);
    }

    private function upsert(Request $request, string $id, string $slug, array $data)
    {
        if (! Guid::isV4($id)) {
            return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('path', 'id'), 'msg': 'Input should be a valid UUID', 'input': '$id'}");
        }

        // ratings.py:59 — permission first, recipe lookup second.
        if ($denied = UserPermissions::selfOnly($request->attributes->get('mealie_user'), $id)) {
            return $denied;
        }

        $recipe = $this->recipeOr404($request, $slug);
        if ($recipe === null) {
            return new JsonResponse(['detail' => ['message' => 'Not found.', 'error' => true, 'exception' => null]], 404);
        }

        $db = DB::connection('mealie');
        $existing = $db->table('users_to_recipes')
            ->where('user_id', Guid::pack($id))->where('recipe_id', $recipe->id)->first();

        $now = gmdate('Y-m-d H:i:s.u');

        if (! $existing) {
            // ratings.py:63-71 — a new row takes is_favorite false when it was not given.
            $db->table('users_to_recipes')->insert([
                'id' => Str::uuid()->getHex()->toString(),
                'user_id' => Guid::pack($id),
                'recipe_id' => $recipe->id,
                'rating' => $data['rating'],
                'is_favorite' => (bool) ($data['is_favorite'] ?? false),
                'created_at' => $now,
                'update_at' => $now,
            ]);
        } else {
            // ratings.py:73-78 — only the fields that were sent are changed.
            $update = ['update_at' => $now];
            if ($data['rating'] !== null) {
                $update['rating'] = $data['rating'];
            }
            if ($data['is_favorite'] !== null) {
                $update['is_favorite'] = (bool) $data['is_favorite'];
            }
            $db->table('users_to_recipes')->where('id', $existing->id)->update($update);
        }

        // The handler returns None, so the body is null with status 200.
        return Errors::nullBody();
    }

    /**
     * ratings.py:23-42 — resolve as a UUID first, fall back to a slug, scoped to the caller's
     * group rather than to the user being acted on.
     */
    private function recipeOr404(Request $request, string $slugOrId): ?object
    {
        $groupId = $request->attributes->get('mealie_user')->group_id;
        $recipes = DB::connection('mealie')->table('recipes')->where('group_id', $groupId);

        return Guid::pack($slugOrId) !== null
            ? $recipes->where('id', Guid::pack($slugOrId))->first()
            : $recipes->where('slug', $slugOrId)->first();
    }
}
