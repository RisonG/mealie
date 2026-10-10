<?php

namespace App\Http\Controllers;

use App\Support\Dates;
use App\Support\Errors;
use App\Support\Guid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** mealie/routes/groups/controller_group_reports.py — authenticated, no permission check. */
class GroupReportsController extends Controller
{
    /** :35 — a bare JSON array of ReportSummary, not a pagination envelope. */
    public function index(Request $request): JsonResponse
    {
        $query = $this->scoped($request);

        if ($type = $request->query('report_type')) {
            $query->where('category', $type);
        }

        $rows = $query->limit(9999)->get()->map(fn ($r) => $this->summary($r))->all();

        return new JsonResponse($rows);
    }

    /** :39 — unknown id is a 404 from HttpRepo.get_one. */
    public function show(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('path', 'item_id'), 'msg': 'Input should be a valid UUID', 'input': '$itemId'}");
        }

        $report = $this->scoped($request)->where('id', Guid::pack($itemId))->first();
        if (! $report) {
            return Errors::repoNotFound();
        }

        $entries = DB::connection('mealie')->table('report_entries')
            ->where('report_id', $report->id)->orderBy('timestamp')
            // ReportEntryOut (reports.py:36) inherits ReportEntryCreate, so id comes last.
            ->get()->map(fn ($e) => [
                'reportId' => Guid::dash($e->report_id),
                'timestamp' => Dates::out($e->timestamp),
                'success' => (bool) $e->success,
                'message' => $e->message,
                'exception' => $e->exception,
                'id' => Guid::dash($e->id),
            ])->all();

        return new JsonResponse($this->summary($report) + ['entries' => $entries]);
    }

    /**
     * :43 — the handler catches every exception from delete_one and answers 500, so deleting an id
     * that does not exist is a 500 here while GET on the same id is a 404.
     */
    public function destroy(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('path', 'item_id'), 'msg': 'Input should be a valid UUID', 'input': '$itemId'}");
        }

        $report = $this->scoped($request)->where('id', Guid::pack($itemId))->first();
        if (! $report) {
            return new JsonResponse(
                ['detail' => ['message' => 'Failed to delete report', 'error' => true, 'exception' => null]],
                500
            );
        }

        DB::connection('mealie')->table('group_reports')->where('id', $report->id)->delete();

        return new JsonResponse(['message' => 'Report deleted', 'error' => false]);  // group.report-deleted
    }

    private function scoped(Request $request)
    {
        return DB::connection('mealie')->table('group_reports')
            ->where('group_id', $request->attributes->get('mealie_user')->group_id);
    }

    private function summary(object $r): array
    {
        return [
            'timestamp' => Dates::out($r->timestamp),
            'category' => $r->category,
            'groupId' => Guid::dash($r->group_id),
            'name' => $r->name,
            'id' => Guid::dash($r->id),
            'status' => $r->status,
        ];
    }
}
