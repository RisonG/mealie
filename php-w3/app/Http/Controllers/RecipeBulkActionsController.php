<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * mealie/routes/recipe/bulk_actions.py — APIRouter(prefix="/bulk-actions") included with
 * prefix="/recipes". The export routes on the same controller are round 7.
 *
 * All four handlers have no return statement, so each answers a bare null with 200. There is a
 * "# TODO Should these actions return some success response?" at :36 acknowledging it.
 */
class RecipeBulkActionsController extends Controller
{
    /** :37 */
    public function tag(Request $request): JsonResponse
    {
        return $this->guard($request);
    }

    /** :41 */
    public function settings(Request $request): JsonResponse
    {
        return $this->guard($request);
    }

    /** :45 */
    public function categorize(Request $request): JsonResponse
    {
        return $this->guard($request);
    }

    /** :49 — PermissionDenied from RecipeService becomes a 403 ErrorResponse. */
    public function destroy(Request $request): JsonResponse
    {
        return $this->guard($request);
    }

    /** :60 — 202; builds a zip in the background. */
    public function exportStart(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        return Errors::detail(501, 'Bulk recipe export is not implemented in this rewrite');
    }

    /** :76 — a bare array of GroupDataExport. Verified [] on this deployment, so this is exact. */
    public function exportList(Request $request): JsonResponse
    {
        $rows = \Illuminate\Support\Facades\DB::connection('mealie')->table('group_data_exports')
            ->where('group_id', $request->attributes->get('mealie_user')->group_id)
            ->orderBy('expires')->get()
            ->map(fn ($e) => [
                'id' => \App\Support\Guid::dash($e->id),
                'groupId' => \App\Support\Guid::dash($e->group_id),
                'name' => $e->name,
                'filename' => $e->filename,
                'size' => $e->size,
                'expires' => \App\Support\Dates::out($e->expires),
            ])->all();

        return new JsonResponse($rows);
    }

    /** :65 — an unknown export is a 404 with a bare string detail. */
    public function exportDownload(Request $request, string $exportId): JsonResponse
    {
        if (! \App\Support\Guid::isV4($exportId)) {
            return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('path', 'export_id'), 'msg': 'Input should be a valid UUID', 'input': '$exportId'}");
        }

        $export = \Illuminate\Support\Facades\DB::connection('mealie')->table('group_data_exports')
            ->where('group_id', $request->attributes->get('mealie_user')->group_id)
            ->where('id', \App\Support\Guid::pack($exportId))->first();

        if (! $export) {
            return Errors::detail(404, 'export not found');
        }

        // The file token is minted from the export's path on disk; not implemented.
        return Errors::detail(501, 'Downloading a bulk export is not implemented in this rewrite');
    }

    /**
     * :80 — the count is computed, and with nothing to purge nothing is written. Verified
     * {"message":"0 exports deleted","error":false}, so this one is exact in the state that
     * matters here.
     */
    public function exportPurge(Request $request): JsonResponse
    {
        $count = \Illuminate\Support\Facades\DB::connection('mealie')->table('group_data_exports')
            ->where('group_id', $request->attributes->get('mealie_user')->group_id)
            ->where('expires', '<', gmdate('Y-m-d H:i:s.u'))->count();

        if ($count > 0) {
            return Errors::detail(501, 'Purging expired exports is not implemented in this rewrite');
        }

        return new JsonResponse(['message' => '0 exports deleted', 'error' => false]);
    }

    /** Every one of the four bodies requires `recipes`, so an empty object is a 422. */
    private function guard(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        if (! isset($body['recipes']) || ! is_array($body['recipes'])) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'recipes'), 'msg': 'Field required', 'input': {}}");
        }

        return Errors::detail(501, 'Bulk recipe actions are not implemented in this rewrite');
    }
}
