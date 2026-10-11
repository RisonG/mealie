<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use App\Support\Guid;
use App\Support\RecipeSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * recipe_crud_routes.py — images and assets, plus exports.py and comments.py.
 *
 * The five image and asset routes fetch URLs through the SSRF allow-list, re-encode images and
 * write to the recipe's data directory; none of that is implemented. Their validation and the
 * handle_exceptions mapping are.
 */
class RecipeMediaController extends Controller
{
    /** :782 — three distinct 400s, see the contract. */
    public function scrapeImage(Request $request, string $slug): JsonResponse
    {
        if ($invalid = $this->requireUrl($request)) {
            return $invalid;
        }

        return $this->recipe($request, $slug)
            ? Errors::detail(501, 'Scraping a recipe image is not implemented in this rewrite')
            : $this->noEntry();
    }

    /** :812 — multipart: `image` bytes and `extension` as a form field. */
    public function updateImage(Request $request, string $slug): JsonResponse
    {
        if (! $request->hasFile('image')) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'image'), 'msg': 'Field required', 'input': None}");
        }

        return $this->recipe($request, $slug)
            ? Errors::detail(501, 'Updating a recipe image is not implemented in this rewrite')
            : $this->noEntry();
    }

    /** :821 — SuccessResponse with the translated recipe.recipe-image-deleted. */
    public function deleteImage(Request $request, string $slug): JsonResponse
    {
        return $this->recipe($request, $slug)
            ? Errors::detail(501, 'Deleting a recipe image is not implemented in this rewrite')
            : $this->noEntry();
    }

    /** :884 — multipart: name, icon, extension form fields plus a file. */
    public function uploadAsset(Request $request, string $slug): JsonResponse
    {
        foreach (['name', 'icon', 'extension'] as $field) {
            if ($request->input($field) === null) {
                return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', '$field'), 'msg': 'Field required', 'input': None}");
            }
        }

        if (! $request->hasFile('file')) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'file'), 'msg': 'Field required', 'input': None}");
        }

        return $this->recipe($request, $slug)
            ? Errors::detail(501, 'Uploading recipe assets is not implemented in this rewrite')
            : $this->noEntry();
    }

    /** :896 — downloads an image to store as an asset. */
    public function assetFromUrl(Request $request, string $slug): JsonResponse
    {
        if ($invalid = $this->requireUrl($request)) {
            return $invalid;
        }

        return $this->recipe($request, $slug)
            ? Errors::detail(501, 'Creating a recipe asset from a URL is not implemented in this rewrite')
            : $this->noEntry();
    }

    /** exports.py:21 — the template list, static for this deployment, so this one is exact. */
    public function formats(): JsonResponse
    {
        return new JsonResponse(['json' => ['raw'], 'zip' => ['zip']]);
    }

    /** exports.py:25 — template_name is a required query parameter, so omitting it is a 422. */
    public function exportRecipe(Request $request, string $slug): JsonResponse
    {
        if ($request->query('template_name') === null) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('query', 'template_name'), 'msg': 'Field required', 'input': None}");
        }

        $recipe = $this->recipe($request, $slug);
        if (! $recipe) {
            return Errors::repoNotFound();
        }

        /*
         * The "raw" template is the recipe itself: TemplateService renders it to a file and
         * FileResponse serves it as application/json. Verified byte-identical to
         * GET /recipes/{slug} apart from the 4-space indentation the file carries.
         */
        if ($request->query('template_name') === 'raw') {
            return new JsonResponse(RecipeSerializer::full($recipe), 200, [], JSON_PRETTY_PRINT);
        }

        return Errors::detail(501, 'Rendering a recipe export is not implemented in this rewrite');
    }

    /**
     * comments.py:10 — a bare array. An unknown slug is a 500: comments.py:13-14 dereferences
     * recipe.id with no null check, so the AttributeError escapes and Starlette answers plain text.
     * Reproduced deliberately.
     */
    public function comments(Request $request, string $slug)
    {
        $recipe = $this->recipe($request, $slug);
        if (! $recipe) {
            return Errors::serverError();
        }

        $rows = DB::connection('mealie')->table('recipe_comments')
            ->where('recipe_id', $recipe->id)->orderBy('created_at')->get()
            ->map(fn ($c) => [
                'recipeId' => Guid::dash($c->recipe_id),
                'text' => $c->text,
                'id' => Guid::dash($c->id),
                'createdAt' => \App\Support\Dates::out($c->created_at),
                'updatedAt' => \App\Support\Dates::out($c->update_at),
                'userId' => Guid::dash($c->user_id),
                'user' => null,
            ])->all();

        return new JsonResponse($rows);
    }

    private function recipe(Request $request, string $slugOrId): ?object
    {
        $query = DB::connection('mealie')->table('recipes')
            ->where('group_id', $request->attributes->get('mealie_user')->group_id);
        $packed = Guid::pack($slugOrId);

        return $packed !== null ? $query->where('id', $packed)->first() : $query->where('slug', $slugOrId)->first();
    }

    private function requireUrl(Request $request): ?JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();

        return isset($body['url']) && is_string($body['url'])
            ? null
            : Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'url'), 'msg': 'Field required', 'input': {}}");
    }

    private function noEntry(): JsonResponse
    {
        return new JsonResponse(['detail' => ['message' => 'No Entry Found', 'error' => true, 'exception' => null]], 404);
    }
}
