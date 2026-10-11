<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * mealie/routes/recipe/recipe_crud_routes.py — the scraping and import half.
 *
 * None of these ten is implemented: each fetches a third-party URL, runs recipe_scrapers, calls an
 * AI provider, or unpacks an uploaded archive. The request validation *is* implemented, so a
 * malformed body gets the same 422 the oracle gives; nothing is fetched, parsed or written.
 *
 * The three streaming variants would additionally need server-sent events, which is not attempted.
 */
class RecipeImportController extends Controller
{
    /** :158 — ScrapeRecipeTest. A scrape timeout is a 408; a failed scrape is a bare string. */
    public function testScrapeUrl(Request $request): JsonResponse
    {
        return $this->requireUrl($request) ?? $this->notImplemented('URL scraping');
    }

    /** :184 — 201, bare string slug. A body starting with { is converted from ld+json first. */
    public function createFromHtmlOrJson(Request $request): JsonResponse
    {
        return $this->requireData($request) ?? $this->notImplemented('Creating recipes from HTML or JSON');
    }

    /** :200 — SSE */
    public function createFromHtmlOrJsonStream(Request $request): JsonResponse
    {
        return $this->requireData($request) ?? $this->notImplemented('Streaming recipe creation');
    }

    /** :213 — 201, bare string slug. */
    public function createFromUrl(Request $request): JsonResponse
    {
        return $this->requireUrl($request) ?? $this->notImplemented('Creating recipes from a URL');
    }

    /** :226 — SSE */
    public function createFromUrlStream(Request $request): JsonResponse
    {
        return $this->requireUrl($request) ?? $this->notImplemented('Streaming recipe creation');
    }

    /**
     * :380 — 201, bare string slug.
     *
     * Every parameter is a **Form** field (:383-387), not a JSON body, so a JSON payload is
     * ignored rather than rejected and the handler always runs. With no content, images or URL it
     * answers 400 before reaching any AI provider, which is the branch reproduced here.
     */
    public function createFromAi(Request $request): JsonResponse
    {
        if ($this->hasAiInput($request)) {
            return $this->notImplemented('Creating recipes with AI');
        }

        return new JsonResponse([
            'detail' => ['message' => 'No content, images, or URL were provided', 'error' => true, 'exception' => null],
        ], 400);
    }

    /** :409 — the same guard, delivered as a server-sent event with status 200. */
    public function createFromAiStream(Request $request)
    {
        if ($this->hasAiInput($request)) {
            return $this->notImplemented('Streaming recipe creation');
        }

        $body = "event: error\ndata: {\"message\": \"No content, images, or URL were provided\"}\n\n";

        return new \Illuminate\Http\Response($body, 200, ['Content-Type' => 'text/event-stream; charset=utf-8']);
    }

    /** The AI routes take Form fields; anything that would let them proceed counts as input. */
    private function hasAiInput(Request $request): bool
    {
        return $request->input('content') !== null
            || $request->input('url') !== null
            || $request->hasFile('images');
    }

    /** :451 — 202, and the one import route that answers with an object: {"reportId": …}. */
    public function createFromUrlBulk(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        if (! isset($body['imports']) || ! is_array($body['imports'])) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'imports'), 'msg': 'Field required', 'input': {}}");
        }

        return $this->notImplemented('Bulk URL import');
    }

    /** :470 — 201, multipart `archive`. */
    public function createFromZip(Request $request): JsonResponse
    {
        if (! $request->hasFile('archive')) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'archive'), 'msg': 'Field required', 'input': None}");
        }

        return $this->notImplemented('Creating recipes from a zip archive');
    }

    /**
     * :484 — deprecated and include_in_schema=False, so it is absent from the OpenAPI document but
     * still routed. Multipart `images` (a list), with translateLanguage as a query parameter.
     */
    public function createFromImage(Request $request): JsonResponse
    {
        if (! $request->hasFile('images')) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'images'), 'msg': 'Field required', 'input': None}");
        }

        return $this->notImplemented('Creating recipes from images');
    }

    /** ScrapeRecipe / ScrapeRecipeTest both require `url`. */
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

    /** ScrapeRecipeData requires `data`. */
    private function requireData(Request $request): ?JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();

        return isset($body['data']) && is_string($body['data'])
            ? null
            : Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'data'), 'msg': 'Field required', 'input': {}}");
    }

    private function notImplemented(string $what): JsonResponse
    {
        return Errors::detail(501, "$what is not implemented in this rewrite");
    }
}
