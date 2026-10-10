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
 * mealie/routes/households/controller_webhooks.py — authenticated, no permission check.
 *
 * Two routes return a bare null body with 200 (rerun and test), which Laravel renders as {} unless
 * the body is set explicitly — the same trap round 2 hit on forgot-password.
 */
class HouseholdWebhooksController extends Controller
{
    /** :28 */
    public function index(Request $request): JsonResponse
    {
        return new JsonResponse(Pagination::paginate(
            $this->scoped($request),
            Pagination::query($request),
            fn ($w) => HouseholdSerializer::webhook($w),
            '/households/webhooks'
        ));
    }

    /** :38 — 201 */
    public function store(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        if (! isset($body['scheduledTime'])) {   // CreateWebhook.scheduled_time has no default
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'scheduledTime'), 'msg': 'Field required', 'input': None}");
        }

        return Errors::detail(501, 'Creating webhooks is not implemented in this rewrite');
    }

    /** :43 — a literal path, declared before /{item_id}. Returns null with 200. */
    public function rerun(Request $request): JsonResponse
    {
        // post_group_webhooks sends to user-configured URLs; not implemented (contract §4.31).
        return Errors::nullBody();
    }

    /** :51 */
    public function show(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        $webhook = $this->find($request, $itemId);

        return $webhook ? new JsonResponse(HouseholdSerializer::webhook($webhook)) : Errors::repoNotFound();
    }

    /** :55 — 404 for an unknown id, then queues a background send and returns null with 200. */
    public function test(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        if (! $this->find($request, $itemId)) {
            return Errors::repoNotFound();
        }

        // post_test_webhook is not implemented; the 404 path above is.
        return Errors::nullBody();
    }

    /** :60 */
    public function update(Request $request, string $itemId): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        if (! $this->find($request, $itemId)) {
            return Errors::repoNotFound();
        }

        return Errors::detail(501, 'Updating webhooks is not implemented in this rewrite');
    }

    /** :64 — returns the deleted webhook. */
    public function destroy(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        if (! $this->find($request, $itemId)) {
            return Errors::repoNotFound();
        }

        return Errors::detail(501, 'Deleting webhooks is not implemented in this rewrite');
    }

    private function scoped(Request $request)
    {
        $user = $request->attributes->get('mealie_user');

        return DB::connection('mealie')->table('webhook_urls')
            ->where('group_id', $user->group_id)->where('household_id', $user->household_id);
    }

    private function find(Request $request, string $itemId): ?object
    {
        return $this->scoped($request)->where('id', Guid::pack($itemId))->first();
    }

    private function uuidError(string $value): JsonResponse
    {
        return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('path', 'item_id'), 'msg': 'Input should be a valid UUID', 'input': '$value'}");
    }
}
