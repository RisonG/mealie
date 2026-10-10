<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use App\Support\HouseholdSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** mealie/routes/households/controller_invitations.py */
class HouseholdInvitationsController extends Controller
{
    /**
     * :23 — admin only, by an explicit check rather than a router dependency. Returns a bare array:
     * it pages internally with per_page=-1 and returns .items, so there is no envelope.
     */
    public function index(Request $request): JsonResponse
    {
        if (! (bool) $request->attributes->get('mealie_user')->admin) {
            return Errors::detail(403, 'Only admins can list invite tokens');
        }

        $user = $request->attributes->get('mealie_user');
        $rows = DB::connection('mealie')->table('invite_tokens')
            ->where('group_id', $user->group_id)->where('household_id', $user->household_id)
            ->orderBy('created_at', 'desc')->get()
            ->map(fn ($t) => HouseholdSerializer::inviteToken($t))->all();

        return new JsonResponse($rows);
    }

    /**
     * :33 — 201. Guards in order: password login disabled → 403; can_invite false → 403; a
     * non-admin naming another group or household → 403. On this deployment ALLOW_PASSWORD_LOGIN
     * is true, so the first never fires and bob stops at the second.
     */
    public function store(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        if (! isset($body['uses'])) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'uses'), 'msg': 'Field required', 'input': None}");
        }

        if (! config('mealie.allow_password_login')) {
            return Errors::detail(403, 'Invitation tokens are disabled when password login is not allowed');
        }

        $user = $request->attributes->get('mealie_user');
        if (! (bool) $user->can_invite) {
            return Errors::detail(403, 'User is not allowed to create invite tokens');
        }

        $groupId = $body['groupId'] ?? null;
        $householdId = $body['householdId'] ?? null;
        $wrongScope = ($groupId !== null && \App\Support\Guid::pack((string) $groupId) !== $user->group_id)
            || ($householdId !== null && \App\Support\Guid::pack((string) $householdId) !== $user->household_id);

        if (! (bool) $user->admin && $wrongScope) {
            return Errors::detail(403, 'Only admins can create invite tokens for other groups or households');
        }

        // The token row insert is not implemented; see the contract, §4.27.
        return Errors::detail(501, 'Creating invite tokens is not implemented in this rewrite');
    }

    /**
     * :62 — the same two guards with different wording. Note it never fails on a send error: every
     * exception is caught and the response is 200 with success false (:84-90).
     */
    public function email(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        foreach (['email', 'token'] as $field) {
            if (! isset($body[$field]) || ! is_string($body[$field])) {
                return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', '$field'), 'msg': 'Field required', 'input': None}");
            }
        }

        if (! config('mealie.allow_password_login')) {
            return Errors::detail(403, 'Invitation email are disabled when password login is not allowed');
        }

        if (! (bool) $request->attributes->get('mealie_user')->can_invite) {
            return Errors::detail(403, "This user can't send email invitations");
        }

        // Email delivery is not implemented (contract §4.28). Python would answer 200 with
        // success false and the SMTP error here; reporting that would be a guess about the error.
        return Errors::detail(501, 'Sending invitation email is not implemented in this rewrite');
    }
}
