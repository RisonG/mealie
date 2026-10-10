<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use App\Support\GroupSerializer;
use App\Support\Guid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * mealie/routes/groups/controller_group_ai_providers.py.
 *
 * Every one of these eight calls self.checks.can_manage() first (checks.py:28-31), which raises a
 * bare HTTPException(403). The parity token belongs to bob, whose can_manage is 0, so all eight are
 * 403 for it — the check is what is reproduced here, not the status.
 */
class GroupAiProvidersController extends Controller
{
    /** :27 */
    public function settings(Request $request): JsonResponse
    {
        if ($denied = $this->canManage($request)) {
            return $denied;
        }

        $groupId = $request->attributes->get('mealie_user')->group_id;

        return new JsonResponse(GroupSerializer::aiProviderSettings($groupId));
    }

    /** :33 */
    public function updateSettings(Request $request): JsonResponse
    {
        if ($invalid = $this->validateSettingsBody($request)) {
            return $invalid;
        }

        if ($denied = $this->canManage($request)) {
            return $denied;
        }

        $groupId = $request->attributes->get('mealie_user')->group_id;
        $body = $request->json()->all();

        $update = [];
        foreach (['defaultProviderId' => 'default_provider_id', 'audioProviderId' => 'audio_provider_id', 'imageProviderId' => 'image_provider_id'] as $camel => $column) {
            if (array_key_exists($camel, $body)) {
                // ai_providers.py:94-96 — an empty value is stored as null.
                $update[$column] = $body[$camel] ? Guid::pack((string) $body[$camel]) : null;
            }
        }
        if ($update) {
            DB::connection('mealie')->table('ai_provider_settings')->where('group_id', $groupId)->update($update);
        }

        return new JsonResponse(GroupSerializer::aiProviderSettings($groupId));
    }

    /** :46 */
    public function store(Request $request): JsonResponse
    {
        if ($invalid = $this->validateProviderBody($request)) {
            return $invalid;
        }

        if ($denied = $this->canManage($request)) {
            return $denied;
        }

        return Errors::detail(501, 'Creating AI providers is not implemented in this rewrite');
    }

    /**
     * :52 and :70 — both reach OpenAIService.test_connection, which calls a third-party API.
     * The permission check and the 422/404 paths are implemented; the outbound request is not, and
     * is reported rather than answered with a fabricated result.
     */
    public function test(Request $request): JsonResponse
    {
        if ($invalid = $this->validateProviderBody($request)) {
            return $invalid;
        }

        if ($denied = $this->canManage($request)) {
            return $denied;
        }

        return Errors::detail(501, 'Testing an AI provider is not implemented in this rewrite');
    }

    /** :70 */
    public function testSaved(Request $request, string $providerId): JsonResponse
    {
        // overrides is Optional, so only a body that was actually sent is validated.
        if ($request->getContent() !== '' && ($invalid = $this->validateProviderBody($request))) {
            return $invalid;
        }

        if (! Guid::isV4($providerId)) {
            return $this->uuidError($providerId);
        }

        if ($denied = $this->canManage($request)) {
            return $denied;
        }

        if (! $this->find($request, $providerId)) {
            return Errors::repoNotFound();
        }

        return Errors::detail(501, 'Testing an AI provider is not implemented in this rewrite');
    }

    /** :100 */
    public function show(Request $request, string $providerId): JsonResponse
    {
        if (! Guid::isV4($providerId)) {
            return $this->uuidError($providerId);
        }

        if ($denied = $this->canManage($request)) {
            return $denied;
        }

        $provider = $this->find($request, $providerId);

        return $provider ? new JsonResponse($this->out($provider)) : Errors::repoNotFound();
    }

    /** :106 */
    public function update(Request $request, string $providerId): JsonResponse
    {
        if ($invalid = $this->validateProviderBody($request)) {
            return $invalid;
        }

        if (! Guid::isV4($providerId)) {
            return $this->uuidError($providerId);
        }

        if ($denied = $this->canManage($request)) {
            return $denied;
        }

        if (! $this->find($request, $providerId)) {
            return Errors::repoNotFound();
        }

        return Errors::detail(501, 'Updating AI providers is not implemented in this rewrite');
    }

    /** :112 — returns the deleted provider. */
    public function destroy(Request $request, string $providerId): JsonResponse
    {
        if (! Guid::isV4($providerId)) {
            return $this->uuidError($providerId);
        }

        if ($denied = $this->canManage($request)) {
            return $denied;
        }

        if (! $this->find($request, $providerId)) {
            return Errors::repoNotFound();
        }

        return Errors::detail(501, 'Deleting AI providers is not implemented in this rewrite');
    }

    /**
     * FastAPI validates the request body before the handler runs, and checks.can_manage() lives
     * *inside* the handler. So a malformed body is a 422 even for a caller who would have been
     * refused with a 403 — the order below is not interchangeable.
     *
     * AIProviderCreate/Update (ai_providers.py:11-45) require name and model; api_key, base_url and
     * timeout have defaults. Every missing field is reported, not just the first.
     */
    private function validateProviderBody(Request $request): ?JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        $missing = [];
        foreach (['name', 'model'] as $field) {
            if (! isset($body[$field]) || ! is_string($body[$field])) {
                $missing[] = $field;
            }
        }

        return $missing === [] ? null : $this->missingFields($missing, $body);
    }

    /** AIProviderSettingsUpdate (:89-92) — all three ids are required, with no default. */
    private function validateSettingsBody(Request $request): ?JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        $missing = [];
        foreach (['defaultProviderId', 'audioProviderId', 'imageProviderId'] as $field) {
            if (! array_key_exists($field, $body)) {
                $missing[] = $field;
            }
        }

        return $missing === [] ? null : $this->missingFields($missing, $body);
    }

    private function missingFields(array $fields, array $body): JsonResponse
    {
        $input = json_encode($body === [] ? new \stdClass : $body);
        $parts = array_map(
            fn ($f) => "{'type': 'missing', 'loc': ('body', '$f'), 'msg': 'Field required', 'input': $input}",
            $fields
        );
        $n = count($fields);
        $label = $n === 1 ? '1 validation error' : "$n validation errors";

        return Errors::unprocessable($label.': '.implode(' ', $parts));
    }

    /** AIProviderOut — api_key is Field(exclude=True) (ai_providers.py:14) and is never returned. */
    private function out(object $p): array
    {
        $headers = DB::connection('mealie')->table('ai_provider_headers')->where('provider_id', $p->id)->get();
        $params = DB::connection('mealie')->table('ai_provider_params')->where('provider_id', $p->id)->get();

        return [
            'name' => $p->name,
            'baseUrl' => $p->base_url,
            'model' => $p->model,
            'timeout' => (int) $p->timeout,
            'requestHeaders' => $headers->pluck('value', 'key_name')->all() ?: new \stdClass,
            'requestParams' => $params->pluck('value', 'key_name')->all() ?: new \stdClass,
            'id' => Guid::dash($p->id),
        ];
    }

    private function find(Request $request, string $providerId): ?object
    {
        $groupId = $request->attributes->get('mealie_user')->group_id;
        $settings = DB::connection('mealie')->table('ai_provider_settings')->where('group_id', $groupId)->first();

        return $settings === null ? null : DB::connection('mealie')->table('ai_providers')
            ->where('settings_id', $settings->id)->where('id', Guid::pack($providerId))->first();
    }

    private function canManage(Request $request): ?JsonResponse
    {
        return (bool) $request->attributes->get('mealie_user')->can_manage ? null : Errors::forbidden();
    }

    private function uuidError(string $value): JsonResponse
    {
        return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('path', 'provider_id'), 'msg': 'Input should be a valid UUID', 'input': '$value'}");
    }
}
