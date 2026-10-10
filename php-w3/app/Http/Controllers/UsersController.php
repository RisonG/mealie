<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use App\Support\Guid;
use App\Support\UserPermissions;
use App\Support\UserSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** mealie/routes/users/crud.py — UserAPIRouter(prefix="/users"), so every route needs a user. */
class UsersController extends Controller
{
    /** crud.py:19 */
    public function self(Request $request): JsonResponse
    {
        return new JsonResponse(UserSerializer::out($request->attributes->get('mealie_user')));
    }

    /** crud.py:23 — UserRatings[UserRatingSummary]: the three-key form. */
    public function selfRatings(Request $request): JsonResponse
    {
        return $this->ratings($request->attributes->get('mealie_user')->id, false, 'summary');
    }

    /** crud.py:38 */
    public function selfFavorites(Request $request): JsonResponse
    {
        return $this->ratings($request->attributes->get('mealie_user')->id, true, 'summary');
    }

    /** crud.py:27 — recipe_id is UUID4, so a non-v4 value is a 422 before the handler. */
    public function selfRatingForRecipe(Request $request, string $recipeId): JsonResponse
    {
        if (! Guid::isV4($recipeId)) {
            return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('path', 'recipe_id'), 'msg': 'Input should be a valid UUID', 'input': '$recipeId'}");
        }

        $row = DB::connection('mealie')->table('users_to_recipes')
            ->where('user_id', Guid::pack($request->attributes->get('mealie_user')->id))
            ->where('recipe_id', Guid::pack($recipeId))->first();

        if (! $row) {
            return $this->errorResponse(404, 'User has not rated this recipe');   // crud.py:33-36
        }

        return new JsonResponse(UserSerializer::ratingSummary($row));
    }

    /** ratings.py:44 — UserRatings[UserRatingOut]: the five-key form, and a permission check. */
    public function userRatings(Request $request, string $id): JsonResponse
    {
        return $this->guardedRatings($request, $id, false);
    }

    /** ratings.py:50 */
    public function userFavorites(Request $request, string $id): JsonResponse
    {
        return $this->guardedRatings($request, $id, true);
    }

    /**
     * crud.py:42 — changes the caller's own password.
     *
     * The success path writes users.password. It is implemented, but the round's self-check only
     * exercises the rejection paths so the shared database is left as it is.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $user = $request->attributes->get('mealie_user');
        $body = $request->json()->all();

        $new = $body['newPassword'] ?? $body['new_password'] ?? null;
        if (! is_string($new) || mb_strlen($new) < 8) {     // ChangePassword.new_password min_length=8
            return Errors::unprocessable("1 validation error: {'type': 'string_too_short', 'loc': ('body', 'newPassword'), 'msg': 'String should have at least 8 characters'}");
        }

        if (($user->auth_method ?? 'MEALIE') === 'LDAP') {
            return $this->errorResponse(400, 'Unable to update password, user is controlled by LDAP');  // user.ldap-update-password-unavailable
        }

        $current = (string) ($body['currentPassword'] ?? $body['current_password'] ?? '');
        if (! password_verify($current, (string) $user->password)) {
            return $this->errorResponse(400, 'Invalid current password');
        }

        DB::connection('mealie')->table('users')->where('id', $user->id)
            ->update(['password' => password_hash($new, PASSWORD_BCRYPT)]);

        return new JsonResponse(['message' => 'Password updated', 'error' => false]);
    }

    /** crud.py:65 — the full assert_user_change_allowed, unlike the self-only callers. */
    public function updateUser(Request $request, string $itemId): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        if (! Guid::isV4($itemId)) {
            return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('path', 'item_id'), 'msg': 'Input should be a valid UUID', 'input': '$itemId'}");
        }

        $body = $request->json()->all();
        if (! is_array($body) || ! isset($body['email'])) {     // UserBase.email is required
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'email'), 'msg': 'Field required', 'input': None}");
        }

        $user = $request->attributes->get('mealie_user');
        if ($denied = UserPermissions::changeAllowed($user, $itemId, $body)) {
            return $denied;
        }

        DB::connection('mealie')->table('users')->where('id', Guid::pack($itemId))->update([
            'username' => $body['username'] ?? null,
            'full_name' => $body['fullName'] ?? $body['full_name'] ?? null,
            'email' => mb_strtolower(trim((string) $body['email'])),
            'advanced' => (bool) ($body['advanced'] ?? false),
            'show_announcements' => (bool) ($body['showAnnouncements'] ?? true),
            'last_read_announcement' => $body['lastReadAnnouncement'] ?? null,
        ]);

        return new JsonResponse(['message' => 'User updated', 'error' => false]);
    }

    /** ratings.py:47/53 call assert_user_change_allowed with the caller as both arguments. */
    private function guardedRatings(Request $request, string $id, bool $favoritesOnly): JsonResponse
    {
        if (! Guid::isV4($id)) {
            return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('path', 'id'), 'msg': 'Input should be a valid UUID', 'input': '$id'}");
        }

        if ($denied = UserPermissions::selfOnly($request->attributes->get('mealie_user'), $id)) {
            return $denied;
        }

        return $this->ratings(Guid::pack($id), $favoritesOnly, 'out');
    }

    private function ratings(string $userId, bool $favoritesOnly, string $shape): JsonResponse
    {
        $query = DB::connection('mealie')->table('users_to_recipes')->where('user_id', Guid::pack($userId));
        if ($favoritesOnly) {
            $query->where('is_favorite', true);
        }

        $rows = $query->get()->map(fn ($r) => $shape === 'summary'
            ? UserSerializer::ratingSummary($r)
            : UserSerializer::ratingOut($r))->all();

        // UserRatings (user.py:105) is a plain BaseModel, so the envelope key stays as written.
        return new JsonResponse(['ratings' => $rows]);
    }

    /** ErrorResponse (responses.py:8) nested under detail — the shape these handlers raise with. */
    private function errorResponse(int $status, string $message): JsonResponse
    {
        return new JsonResponse(['detail' => ['message' => $message, 'error' => true, 'exception' => null]], $status);
    }
}
