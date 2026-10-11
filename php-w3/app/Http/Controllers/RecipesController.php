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
 * mealie/routes/recipe/recipe_crud_routes.py — UserAPIRouter(prefix="/recipes").
 *
 * There are no checks.* calls here. Recipe permissions live inside RecipeService and surface
 * through handle_exceptions (:110-135), which maps by exception type: PermissionDenied → 403
 * "Permission Denied", NoEntryFound → 404 "No Entry Found", IntegrityError → 400 "Recipe already
 * exists". That 404 message is a fourth distinct shape in this API.
 */
class RecipesController extends Controller
{
    /**
     * :508 — note two deviations from every other paginated endpoint, both verified:
     * the response is orjson.dumps(model_dump(...)) returned directly, so datetimes render as
     * "+00:00" rather than "Z"; and the guide merges the real query string and drops every None,
     * so it carries only the parameters that were actually set.
     */
    public function index(Request $request): JsonResponse
    {
        $builder = $this->scoped($request);

        if ($cookbook = $request->query('cookbook')) {
            $found = $this->findCookbook($request, (string) $cookbook);
            if (! $found) {
                return Errors::detail(404, 'cookbook not found');
            }
        }

        if ($search = $request->query('search')) {
            $builder->whereRaw('lower(name) like ?', ['%'.mb_strtolower((string) $search).'%']);
        }

        $q = Pagination::query($request);
        $page = Pagination::paginate($builder, $q, fn ($r) => RecipeSerializer::summary($r, '+00:00'), '/recipes');

        $page['next'] = self::guide('/recipes', $request, $q, $page, +1);
        $page['previous'] = self::guide('/recipes', $request, $q, $page, -1);

        return new JsonResponse($page);
    }

    /** :565 — literal path before /{slug}; same orjson path, so "+00:00" again. */
    public function suggestions(Request $request): JsonResponse
    {
        $limit = (int) ($request->query('limit') ?? 10);

        $rows = $this->scoped($request)->orderBy('created_at', 'desc')->limit($limit)->get()
            ->map(fn ($r) => ['recipe' => RecipeSerializer::summary($r, '+00:00'), 'missingFoods' => [], 'missingTools' => []])
            ->all();

        return new JsonResponse(['items' => $rows]);
    }

    /** :583 — the parameter is a slug **or** an id; full Recipe, 33 keys, pydantic path so "Z". */
    public function show(Request $request, string $slug): JsonResponse
    {
        $recipe = $this->findBySlugOrId($request, $slug);

        return $recipe ? new JsonResponse(RecipeSerializer::full($recipe)) : $this->noEntry();
    }

    /** :594 — 201, and the body is a bare JSON string: the new recipe's slug. */
    public function store(Request $request): JsonResponse
    {
        if ($invalid = $this->requireName($request)) {
            return $invalid;
        }

        return Errors::detail(501, 'Creating recipes is not implemented in this rewrite');
    }

    /** :618 — 201, returns the full Recipe rather than a slug. */
    public function duplicate(Request $request, string $slug): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        if (! $this->findBySlugOrId($request, $slug)) {
            return $this->noEntry();
        }

        return Errors::detail(501, 'Duplicating recipes is not implemented in this rewrite');
    }

    /** :640 */
    public function update(Request $request, string $slug): JsonResponse
    {
        if ($invalid = $this->requireName($request)) {
            return $invalid;
        }

        if (! $this->findBySlugOrId($request, $slug)) {
            return $this->noEntry();
        }

        return Errors::detail(501, 'Updating recipes is not implemented in this rewrite');
    }

    /** :663 — bulk; the body is a JSON array. */
    public function updateMany(Request $request): JsonResponse
    {
        if ($invalid = $this->requireArrayBody($request)) {
            return $invalid;
        }

        return json_decode($request->getContent(), true) === []
            ? new JsonResponse([])
            : Errors::detail(501, 'Bulk recipe update is not implemented in this rewrite');
    }

    /** :688 */
    public function patch(Request $request, string $slug): JsonResponse
    {
        if ($invalid = $this->requireName($request)) {
            return $invalid;
        }

        if (! $this->findBySlugOrId($request, $slug)) {
            return $this->noEntry();
        }

        return Errors::detail(501, 'Patching recipes is not implemented in this rewrite');
    }

    /** :711 — bulk patch. */
    public function patchMany(Request $request): JsonResponse
    {
        if ($invalid = $this->requireArrayBody($request)) {
            return $invalid;
        }

        return json_decode($request->getContent(), true) === []
            ? new JsonResponse([])
            : Errors::detail(501, 'Bulk recipe patch is not implemented in this rewrite');
    }

    /** :736 — body {"timestamp": …}. */
    public function lastMade(Request $request, string $slug): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        if (! isset($body['timestamp'])) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'timestamp'), 'msg': 'Field required', 'input': {}}");
        }

        if (! $this->findBySlugOrId($request, $slug)) {
            return $this->noEntry();
        }

        return Errors::detail(501, 'Updating last-made is not implemented in this rewrite');
    }

    /** :760 — returns the deleted Recipe. */
    public function destroy(Request $request, string $slug): JsonResponse
    {
        if (! $this->findBySlugOrId($request, $slug)) {
            return $this->noEntry();
        }

        return Errors::detail(501, 'Deleting recipes is not implemented in this rewrite');
    }

    /**
     * :535-537 — group-scoped on purpose, across every household; a household filter is supposed
     * to go through queryFilter rather than through the repo.
     */
    private function scoped(Request $request)
    {
        return DB::connection('mealie')->table('recipes')
            ->where('group_id', $request->attributes->get('mealie_user')->group_id);
    }

    private function findBySlugOrId(Request $request, string $slugOrId): ?object
    {
        $packed = Guid::pack($slugOrId);

        return $packed !== null
            ? $this->scoped($request)->where('id', $packed)->first()
            : $this->scoped($request)->where('slug', $slugOrId)->first();
    }

    private function findCookbook(Request $request, string $idOrSlug): ?object
    {
        $query = DB::connection('mealie')->table('cookbooks')
            ->where('group_id', $request->attributes->get('mealie_user')->group_id);
        $packed = Guid::pack($idOrSlug);

        return $packed !== null ? $query->where('id', $packed)->first() : $query->where('slug', $idOrSlug)->first();
    }

    /** handle_exceptions :118-122 — NoEntryFound, whose message is not HttpRepo's "Not found.". */
    private function noEntry(): JsonResponse
    {
        return new JsonResponse(
            ['detail' => ['message' => 'No Entry Found', 'error' => true, 'exception' => null]],
            404
        );
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

    private function requireArrayBody(Request $request): ?JsonResponse
    {
        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) && (array_is_list($decoded) || $decoded === []) ? null
            : Errors::unprocessable("1 validation error: {'type': 'list_type', 'loc': ('body',), 'msg': 'Input should be a valid list'}");
    }

    /**
     * :555-558 — q.model_dump() merged with the real query string, then every None dropped. The
     * result carries only the parameters that were actually set, unlike the orderBy=None form every
     * other endpoint uses.
     */
    private static function guide(string $route, Request $request, array $q, array $page, int $step): ?string
    {
        $target = $page['page'] + $step;
        if ($step > 0 ? $page['page'] >= $page['total_pages'] : $page['page'] <= 1) {
            return null;
        }

        $params = ['orderDirection' => $q['order_direction'] ?? 'desc'];
        foreach ($q as $key => $value) {
            if ($value === null || in_array($key, ['page', 'per_page', 'order_direction'], true)) {
                continue;
            }
            $params[lcfirst(str_replace('_', '', ucwords($key, '_')))] = $value;
        }
        foreach ($request->query() as $key => $value) {
            if ($value !== null && ! in_array($key, ['page', 'perPage'], true)) {
                $params[$key] = $value;
            }
        }
        $params['page'] = $target;
        $params['perPage'] = $page['per_page'];

        return $route.'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
