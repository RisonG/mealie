<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use App\Support\GroupSerializer;
use App\Support\Guid;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * mealie/routes/groups/controller_labels.py — BaseCrudController with no permission check, so any
 * member of the group can create and delete labels.
 *
 * The router's MealieCrudRoute (routes/_base/routers.py:27-50) adds last-modified and no-cache
 * headers only when the body has an updatedAt key. MultiPurposeLabelOut has none, so the wrapper is
 * inert here and no such headers are sent.
 */
class GroupLabelsController extends Controller
{
    /** controller_labels.py:44 */
    public function index(Request $request): JsonResponse
    {
        $builder = $this->scoped($request);

        if ($search = $request->query('search')) {
            $builder->whereRaw('lower(name) like ?', ['%'.mb_strtolower($search).'%']);
        }

        return new JsonResponse(Pagination::paginate(
            $builder,
            Pagination::query($request),
            fn ($l) => GroupSerializer::label($l),
            '/groups/labels'
        ));
    }

    /** controller_labels.py:55 */
    public function store(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        if (! isset($body['name']) || ! is_string($body['name'])) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'name'), 'msg': 'Field required', 'input': None}");
        }

        $groupId = $request->attributes->get('mealie_user')->group_id;
        $id = Str::uuid()->getHex()->toString();
        $now = gmdate('Y-m-d H:i:s.u');

        DB::connection('mealie')->table('multi_purpose_labels')->insert([
            'id' => $id,
            'name' => $body['name'],
            'color' => $body['color'] ?? '#E0E0E0',
            'group_id' => $groupId,
            'created_at' => $now,
            'update_at' => $now,
        ]);

        // The label_created event-bus dispatch (controller_labels.py:62-68) is not implemented;
        // it notifies webhooks and Apprise and does not affect this response.
        return new JsonResponse(GroupSerializer::label($this->find($request, $id)));
    }

    /** controller_labels.py:71 */
    public function show(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('path', 'item_id'), 'msg': 'Input should be a valid UUID', 'input': '$itemId'}");
        }

        $label = $this->find($request, Guid::pack($itemId));

        return $label ? new JsonResponse(GroupSerializer::label($label)) : Errors::repoNotFound();
    }

    /** controller_labels.py:75 */
    public function update(Request $request, string $itemId): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        if (! Guid::isV4($itemId)) {
            return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('path', 'item_id'), 'msg': 'Input should be a valid UUID', 'input': '$itemId'}");
        }

        $label = $this->find($request, Guid::pack($itemId));
        if (! $label) {
            return Errors::repoNotFound();
        }

        $body = $request->json()->all();
        $update = ['update_at' => gmdate('Y-m-d H:i:s.u')];
        foreach (['name', 'color'] as $field) {
            if (array_key_exists($field, $body)) {
                $update[$field] = $body[$field];
            }
        }

        DB::connection('mealie')->table('multi_purpose_labels')->where('id', $label->id)->update($update);

        return new JsonResponse(GroupSerializer::label($this->find($request, $label->id)));
    }

    /** controller_labels.py:87 — returns the deleted row. */
    public function destroy(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('path', 'item_id'), 'msg': 'Input should be a valid UUID', 'input': '$itemId'}");
        }

        $label = $this->find($request, Guid::pack($itemId));
        if (! $label) {
            return Errors::repoNotFound();
        }

        DB::connection('mealie')->table('multi_purpose_labels')->where('id', $label->id)->delete();

        return new JsonResponse(GroupSerializer::label($label));
    }

    private function scoped(Request $request)
    {
        return DB::connection('mealie')->table('multi_purpose_labels')
            ->where('group_id', $request->attributes->get('mealie_user')->group_id);
    }

    private function find(Request $request, ?string $packedId): ?object
    {
        return $packedId === null ? null : $this->scoped($request)->where('id', $packedId)->first();
    }
}
