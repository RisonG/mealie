<?php

namespace App\Http\Controllers;

use App\Support\Dates;
use App\Support\Errors;
use App\Support\Guid;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * mealie/routes/comments/__init__.py — /api/comments. Distinct from /api/recipes/{slug}/comments,
 * which is round 7.
 *
 * _check_comment_belongs_to_user does repo.get_one(item_id) and then comment.user_id with no null
 * check, so update and delete on an id that does not exist raise AttributeError and answer 500
 * before any 404 — the same defect as round 7's recipe comments route.
 */
class CommentsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return new JsonResponse(Pagination::paginate(
            $this->scoped($request),
            Pagination::query($request),
            fn ($c) => $this->out($c),
            '/comments'
        ));
    }

    /** 201 */
    public function store(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        foreach (['recipeId', 'text'] as $field) {
            if (! isset($body[$field])) {
                return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', '$field'), 'msg': 'Field required', 'input': {}}");
            }
        }

        return Errors::detail(501, 'Creating comments is not implemented in this rewrite');
    }

    public function show(Request $request, string $itemId): JsonResponse
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        $row = $this->find($request, $itemId);

        return $row ? new JsonResponse($this->out($row)) : Errors::repoNotFound();
    }

    /** The ownership check runs first and is a 500 for an unknown id. */
    public function update(Request $request, string $itemId)
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        return $this->ownership($request, $itemId) ?? Errors::detail(501, 'Updating comments is not implemented in this rewrite');
    }

    /** Returns SuccessResponse "Comment deleted", not the deleted object. */
    public function destroy(Request $request, string $itemId)
    {
        if (! Guid::isV4($itemId)) {
            return $this->uuidError($itemId);
        }

        return $this->ownership($request, $itemId) ?? Errors::detail(501, 'Deleting comments is not implemented in this rewrite');
    }

    /** null when the caller may proceed; otherwise the 500 or the 403 Python would raise. */
    private function ownership(Request $request, string $itemId)
    {
        $row = $this->find($request, $itemId);
        if (! $row) {
            return Errors::serverError();
        }

        $user = $request->attributes->get('mealie_user');
        if ($row->user_id !== $user->id && ! (bool) $user->admin) {
            return new JsonResponse(['detail' => ['message' => 'Comment does not belong to user']], 403);
        }

        return null;
    }

    private function scoped(Request $request)
    {
        $recipeIds = DB::connection('mealie')->table('recipes')
            ->where('group_id', $request->attributes->get('mealie_user')->group_id)->pluck('id');

        return DB::connection('mealie')->table('recipe_comments')->whereIn('recipe_id', $recipeIds);
    }

    private function find(Request $request, string $itemId): ?object
    {
        return $this->scoped($request)->where('id', Guid::pack($itemId))->first();
    }

    private function out(object $c): array
    {
        return [
            'recipeId' => Guid::dash($c->recipe_id),
            'text' => $c->text,
            'id' => Guid::dash($c->id),
            'createdAt' => Dates::out($c->created_at),
            'updatedAt' => Dates::out($c->update_at),
            'userId' => Guid::dash($c->user_id),
            'user' => null,
        ];
    }

    private function uuidError(string $value): JsonResponse
    {
        return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('path', 'item_id'), 'msg': 'Input should be a valid UUID', 'input': '$value'}");
    }
}
