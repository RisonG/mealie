<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use App\Support\Guid;
use App\Support\HouseholdSerializer;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * mealie/routes/households/controller_cookbooks.py — authenticated, no permission check.
 *
 * Two things set this apart from every other collection in the module. The list and single-read
 * routes use group_cookbooks (:33-35), a repo with household_id=None, so they deliberately see
 * other households' cookbooks. And the id types differ per method: GET takes UUID4|str while PUT
 * and DELETE take a plain str, so a malformed id is a 404 on all three and never a 422.
 */
class CookbooksController extends Controller
{
    /** :51 — group-scoped, not household-scoped. */
    public function index(Request $request): JsonResponse
    {
        return new JsonResponse(Pagination::paginate(
            $this->groupScoped($request),
            Pagination::query($request),
            fn ($c) => HouseholdSerializer::cookbook($c),
            '/households/cookbooks'
        ));
    }

    /** :62 — 201 */
    public function store(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        if (! isset($body['name']) || ! is_string($body['name']) || $body['name'] === '') {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'name'), 'msg': 'Field required', 'input': None}");
        }

        return Errors::detail(501, 'Creating cookbooks is not implemented in this rewrite');
    }

    /** :78 — bulk update. The body is a JSON array, not an object. */
    public function updateMany(Request $request): JsonResponse
    {
        $decoded = json_decode($request->getContent(), true);
        if (! is_array($decoded) || (! array_is_list($decoded) && $decoded !== [])) {
            return Errors::unprocessable("1 validation error: {'type': 'list_type', 'loc': ('body',), 'msg': 'Input should be a valid list'}");
        }

        if ($decoded === []) {
            return new JsonResponse([]);
        }

        return Errors::detail(501, 'Bulk cookbook update is not implemented in this rewrite');
    }

    /**
     * :104 — UUID4|str: a UUID matches on id, anything else on slug. Not found raises
     * HTTPException(404) with **no detail**, which renders as {"detail":"Not Found"} rather than
     * the ErrorResponse object HttpRepo produces elsewhere.
     */
    public function show(Request $request, string $itemId): JsonResponse
    {
        $cookbook = $this->findInGroup($request, $itemId);

        return $cookbook ? new JsonResponse(HouseholdSerializer::cookbook($cookbook)) : Errors::notFound();
    }

    /**
     * :122 — item_id is a plain str, so there is no 422 for the *id*. Measured against the oracle,
     * this path has three outcomes and they are not what the signature suggests:
     *   body fails CreateCookBook validation  → 422 (request validation, before the handler)
     *   id is not a UUID                      → 500. HttpRepo.update_one (mixins.py:118) calls
     *                                           repo.get_one *outside* its try block, so the GUID
     *                                           type's "badly formed hexadecimal UUID string"
     *                                           escapes unhandled
     *   id is a UUID with no row               → 404 ErrorResponse "Not found."
     * Unlike GET, the repo matches on id only here; it never falls back to the slug.
     */
    public function update(Request $request, string $itemId)
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        if (! isset($body['name']) || ! is_string($body['name']) || $body['name'] === '') {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'name'), 'msg': 'Field required', 'input': None}");
        }

        if (Guid::pack($itemId) === null) {
            return Errors::serverError();
        }

        if (! $this->findByIdInHousehold($request, $itemId)) {
            return Errors::repoNotFound();
        }

        return Errors::detail(501, 'Updating cookbooks is not implemented in this rewrite');
    }

    /**
     * :136 — also a plain str, and also id-only. HttpRepo.delete_one (mixins.py:149) *does* wrap
     * the call, so the failures come back through handle_exception (mixins.py:71-95) instead:
     *   id is not a UUID          → 400, the catch-all branch
     *   id is a UUID with no row  → 404, the NoResultFound branch, whose message is
     *                               "An unexpected error occurred" rather than "Not found."
     * Three different 404 messages now exist in this module.
     */
    public function destroy(Request $request, string $itemId): JsonResponse
    {
        if (Guid::pack($itemId) === null) {
            // The oracle's exception field carries the failing SQL statement, which is not
            // meaningful to reproduce; the status and message are.
            return $this->repoError(400, '(builtins.ValueError) badly formed hexadecimal UUID string');
        }

        if (! $this->findByIdInHousehold($request, $itemId)) {
            return $this->repoError(404, 'No row was found when one was required');
        }

        return Errors::detail(501, 'Deleting cookbooks is not implemented in this rewrite');
    }

    /** handle_exception's ErrorResponse: a fixed message with the exception text alongside. */
    private function repoError(int $status, string $exception): JsonResponse
    {
        return new JsonResponse([
            'detail' => ['message' => 'An unexpected error occurred', 'error' => true, 'exception' => $exception],
        ], $status);
    }

    private function findByIdInHousehold(Request $request, string $itemId): ?object
    {
        return $this->householdScoped($request)->where('id', Guid::pack($itemId))->first();
    }

    private function groupScoped(Request $request)
    {
        return DB::connection('mealie')->table('cookbooks')
            ->where('group_id', $request->attributes->get('mealie_user')->group_id);
    }

    private function householdScoped(Request $request)
    {
        $user = $request->attributes->get('mealie_user');

        return DB::connection('mealie')->table('cookbooks')
            ->where('group_id', $user->group_id)->where('household_id', $user->household_id);
    }

    private function findInGroup(Request $request, string $itemId): ?object
    {
        return $this->byIdOrSlug($this->groupScoped($request), $itemId);
    }

    private function findInHousehold(Request $request, string $itemId): ?object
    {
        return $this->byIdOrSlug($this->householdScoped($request), $itemId);
    }

    private function byIdOrSlug($query, string $itemId): ?object
    {
        $packed = Guid::pack($itemId);

        return $packed !== null ? $query->where('id', $packed)->first() : $query->where('slug', $itemId)->first();
    }
}
