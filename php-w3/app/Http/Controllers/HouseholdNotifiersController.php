<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use App\Support\Guid;
use App\Support\HouseholdSerializer;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * mealie/routes/households/controller_group_notifications.py — authenticated, no permission check.
 *
 * Two of the six answer 204 with an empty body rather than returning the object.
 */
class HouseholdNotifiersController extends Controller
{
    /** :54 */
    public function index(Request $request): JsonResponse
    {
        return new JsonResponse(Pagination::paginate(
            $this->scoped($request),
            Pagination::query($request),
            fn ($n) => HouseholdSerializer::notifier($n),
            '/households/events/notifications'
        ));
    }

    /** :64 — 201 */
    public function store(Request $request): JsonResponse
    {
        if ($invalid = $this->requireName($request)) {
            return $invalid;
        }

        return Errors::detail(501, 'Creating event notifiers is not implemented in this rewrite');
    }

    /** :69 */
    public function show(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        $notifier = $this->find($request, $itemId);

        return $notifier ? new JsonResponse(HouseholdSerializer::notifier($notifier)) : Errors::repoNotFound();
    }

    /**
     * :73 — a null appriseUrl in the body means "keep the stored one" (:74-78), because the URL
     * carries credentials and is never echoed to the client, so a client cannot resend it.
     */
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

        return Errors::detail(501, 'Updating event notifiers is not implemented in this rewrite');
    }

    /** :83 — 204, empty body. */
    public function destroy(Request $request, string $itemId)
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        if (! $this->find($request, $itemId)) {
            return Errors::repoNotFound();
        }

        return Errors::detail(501, 'Deleting event notifiers is not implemented in this rewrite');
    }

    /**
     * :91 — 204, empty body. The dispatch itself builds an Event and pushes it to Apprise; that is
     * not implemented. The 404 for an unknown id is.
     */
    public function test(Request $request, string $itemId)
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        if (! $this->find($request, $itemId)) {
            return Errors::repoNotFound();
        }

        return new Response('', 204);
    }

    private function scoped(Request $request)
    {
        $user = $request->attributes->get('mealie_user');

        return DB::connection('mealie')->table('group_events_notifiers')
            ->where('group_id', $user->group_id)->where('household_id', $user->household_id);
    }

    private function find(Request $request, string $itemId): ?object
    {
        return $this->scoped($request)->where('id', Guid::pack($itemId))->first();
    }

    private function requireName(Request $request): ?JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();

        return isset($body['name']) && is_string($body['name'])
            ? null
            : Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'name'), 'msg': 'Field required', 'input': None}");
    }

    private function uuidError(string $value): JsonResponse
    {
        return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('path', 'item_id'), 'msg': 'Input should be a valid UUID', 'input': '$value'}");
    }
}
