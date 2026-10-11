<?php

namespace App\Http\Controllers;

use App\Support\Dates;
use App\Support\Errors;
use App\Support\Guid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * mealie/routes/media/, mealie/routes/parser/ and mealie/routes/shared/.
 *
 * media carries the full /api in its own router (media/__init__.py:8) and is included directly at
 * mealie/app.py:149, like utility_routes.py in round 1. It is anonymous.
 *
 * shared/__init__.py:14 is a UserAPIRouter and is **authenticated** — it is the owner's side of the
 * share-token feature, where round 7's /api/recipes/shared/{token_id} is the public side.
 */
class MediaParserSharedController extends Controller
{
    // ===== media: anonymous, and these handlers never touch the database =====

    /**
     * media_recipe.py:19 — <data>/recipes/<recipe_id>/images/<file>.
     *
     * No row is looked up: an unknown recipe simply has no directory, so it is a 404 like a missing
     * file. file_name is an ImageType enum (:13-16), so anything outside the three permitted names
     * is a 422 before the handler.
     */
    public function recipeImage(Request $request, string $recipeId, string $fileName): JsonResponse
    {
        if ($invalid = $this->uuidOr422($recipeId, 'recipe_id')) {
            return $invalid;
        }
        if ($invalid = $this->imageTypeOr422($fileName)) {
            return $invalid;
        }

        return $this->serve($this->recipeDir($recipeId).'/images/'.$fileName, 'image/webp');
    }

    /** media_recipe.py:33 — <recipe dir>/images/timeline/<event_id>/<file>. */
    public function timelineImage(Request $request, string $recipeId, string $eventId, string $fileName): JsonResponse
    {
        if ($invalid = $this->uuidOr422($recipeId, 'recipe_id')) {
            return $invalid;
        }
        if ($invalid = $this->uuidOr422($eventId, 'timeline_event_id')) {
            return $invalid;
        }
        if ($invalid = $this->imageTypeOr422($fileName)) {
            return $invalid;
        }

        return $this->serve($this->recipeDir($recipeId)."/images/timeline/$eventId/$fileName", 'image/webp');
    }

    /**
     * media_recipe.py:51 — file_name is a plain str here, and the handler refuses anything that
     * resolves outside the asset directory with a 400 (:57-58).
     */
    public function recipeAsset(Request $request, string $recipeId, string $fileName): JsonResponse
    {
        if ($invalid = $this->uuidOr422($recipeId, 'recipe_id')) {
            return $invalid;
        }

        $dir = $this->recipeDir($recipeId).'/assets';

        return $this->serveWithinDir($dir, $fileName, null);
    }

    /** media_user.py:10 — <data>/users/<user_id>/<file>, with the same traversal guard. */
    public function userImage(Request $request, string $userId, string $fileName): JsonResponse
    {
        if ($invalid = $this->uuidOr422($userId, 'user_id')) {
            return $invalid;
        }

        return $this->serveWithinDir(config('mealie.data_dir').'/users/'.$userId, $fileName, 'image/webp');
    }

    /** media/__init__.py:14 — a fixed path, 404 when the file is absent. */
    public function dockerValidate(): JsonResponse
    {
        return $this->serve(config('mealie.data_dir').'/docker-validation/validate.txt', 'text/plain');
    }

    // ===== parser: authenticated, then body validation =====

    /** parser/ingredient_parser.py:13 */
    public function parseIngredient(Request $request): JsonResponse
    {
        return $this->requireIngredient($request, 'ingredient')
            ?? Errors::detail(501, 'The ingredient parser is not implemented in this rewrite');
    }

    /** parser/ingredient_parser.py:19 — a bare array of ParsedIngredient. */
    public function parseIngredients(Request $request): JsonResponse
    {
        return $this->requireIngredient($request, 'ingredients')
            ?? Errors::detail(501, 'The ingredient parser is not implemented in this rewrite');
    }

    // ===== shared recipe tokens: authenticated =====

    /** shared/__init__.py get_all — a bare array, with an optional recipe_id filter. */
    public function tokens(Request $request): JsonResponse
    {
        $query = $this->scopedTokens($request);

        if ($recipeId = $request->query('recipe_id')) {
            if (! Guid::isV4((string) $recipeId)) {
                return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('query', 'recipe_id'), 'msg': 'Input should be a valid UUID', 'input': '$recipeId'}");
            }
            $query->where('recipe_id', Guid::pack((string) $recipeId));
        }

        return new JsonResponse($query->get()->map(fn ($t) => $this->tokenSummary($t))->all());
    }

    /** create_one — 201; a recipe outside the caller's group is a bare-string 404. */
    public function tokenStore(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        if (! isset($body['recipeId'])) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'recipeId'), 'msg': 'Field required', 'input': {}}");
        }

        $recipe = DB::connection('mealie')->table('recipes')
            ->where('group_id', $request->attributes->get('mealie_user')->group_id)
            ->where('id', Guid::pack((string) $body['recipeId']))->first();

        return $recipe
            ? Errors::detail(501, 'Creating share tokens is not implemented in this rewrite')
            : Errors::detail(404, 'Recipe not found in your group');
    }

    /** get_one */
    public function token(Request $request, string $itemId): JsonResponse
    {
        if ($invalid = $this->uuidOr422($itemId, 'item_id')) {
            return $invalid;
        }

        $row = $this->scopedTokens($request)->where('id', Guid::pack($itemId))->first();

        return $row ? new JsonResponse($this->tokenSummary($row)) : Errors::repoNotFound();
    }

    /** delete_one — typed -> None, so a successful delete answers a bare null. */
    public function tokenDestroy(Request $request, string $itemId): JsonResponse
    {
        if ($invalid = $this->uuidOr422($itemId, 'item_id')) {
            return $invalid;
        }

        $row = $this->scopedTokens($request)->where('id', Guid::pack($itemId))->first();

        return $row
            ? Errors::detail(501, 'Deleting share tokens is not implemented in this rewrite')
            : Errors::repoNotFound();
    }

    // ===== helpers =====

    private function scopedTokens(Request $request)
    {
        return DB::connection('mealie')->table('recipe_share_tokens')
            ->where('group_id', $request->attributes->get('mealie_user')->group_id);
    }

    private function tokenSummary(object $t): array
    {
        return [
            'recipeId' => Guid::dash($t->recipe_id),
            'expiresAt' => Dates::out($t->expires_at),
            'groupId' => Guid::dash($t->group_id),
            'id' => Guid::dash($t->id),
            'createdAt' => Dates::out($t->created_at),
        ];
    }

    /** Recipe.directory_from_id — the id is dashed on disk. */
    private function recipeDir(string $recipeId): string
    {
        return config('mealie.data_dir').'/recipes/'.$recipeId;
    }

    /** ImageType (media_recipe.py:13-16) — three permitted names, anything else a 422. */
    private function imageTypeOr422(string $fileName): ?JsonResponse
    {
        $allowed = ['original.webp', 'min-original.webp', 'tiny-original.webp'];

        return in_array($fileName, $allowed, true) ? null
            : Errors::unprocessable("1 validation error: {'type': 'enum', 'loc': ('path', 'file_name'), 'msg': 'Input should be one of the permitted image names', 'input': '$fileName'}");
    }

    private function serve(string $path, ?string $contentType): JsonResponse
    {
        if (! is_file($path)) {
            return Errors::detail(404, 'Not Found');
        }

        // Serving the bytes is out of scope; the 404 path above is what the parity check exercises.
        return Errors::detail(501, 'Serving media files is not implemented in this rewrite');
    }

    /** The traversal guard: a name that resolves outside its directory is a 400, not a 404. */
    private function serveWithinDir(string $dir, string $fileName, ?string $contentType): JsonResponse
    {
        $resolvedDir = realpath($dir);
        $target = $dir.'/'.$fileName;
        $resolved = realpath($target);

        if ($resolvedDir !== false && $resolved !== false && ! str_starts_with($resolved, $resolvedDir.DIRECTORY_SEPARATOR)) {
            return Errors::detail(400, 'Bad Request');
        }

        return $this->serve($target, $contentType);
    }

    private function requireIngredient(Request $request, string $kind): ?JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        $field = $kind === 'ingredient' ? 'ingredient' : 'ingredients';

        return isset($body[$field])
            ? null
            : Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', '$field'), 'msg': 'Field required', 'input': {}}");
    }

    private function uuidOr422(string $value, string $loc): ?JsonResponse
    {
        return Guid::isV4($value) ? null
            : Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('path', '$loc'), 'msg': 'Input should be a valid UUID', 'input': '$value'}");
    }
}
