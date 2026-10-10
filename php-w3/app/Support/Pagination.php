<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;

/**
 * PaginationBase (mealie/schema/response/pagination.py:51) and the repository's page_all
 * (mealie/repos/repository_generic.py:321-412).
 *
 * The envelope is a plain pydantic BaseModel, so its keys stay snake_case even though the objects
 * inside `items` are MealieModels and are camelCased. Query parameters go the other way:
 * PaginationQuery is a MealieModel, so clients send perPage/orderBy/orderDirection.
 */
class Pagination
{
    /** PaginationQuery defaults: page 1, per_page 50, order_direction desc (pagination.py:35,46-48). */
    public static function query(Request $request): array
    {
        return [
            'page' => (int) ($request->query('page') ?? 1),
            'per_page' => (int) ($request->query('perPage') ?? $request->query('per_page') ?? 50),
            'order_by' => $request->query('orderBy') ?? $request->query('order_by'),
            'order_by_null_position' => $request->query('orderByNullPosition') ?? $request->query('order_by_null_position'),
            'order_direction' => $request->query('orderDirection') ?? $request->query('order_direction') ?? 'desc',
            'query_filter' => $request->query('queryFilter') ?? $request->query('query_filter'),
            'pagination_seed' => $request->query('paginationSeed') ?? $request->query('pagination_seed'),
        ];
    }

    /**
     * Runs the count and the page, then builds the envelope.
     *
     * $mapper turns one database row into the item schema. $route is the path the guides are built
     * from — with no /api prefix, because Python builds it from url_path_for on the sub-router.
     */
    public static function paginate(Builder $builder, array $q, callable $mapper, string $route): array
    {
        $count = (clone $builder)->count();

        // repository_generic.py:388-391 — -1 means "everything".
        $perPage = $q['per_page'];
        $limit = $perPage;
        if ($perPage === -1) {
            $perPage = $count;
            $limit = null;
        }

        // repository_generic.py:393-396 — a per_page of 0 divides by zero and yields 0 pages.
        $totalPages = $perPage === 0 ? 0 : (int) ceil($count / $perPage);

        $page = $q['page'];
        if ($page === -1) {              // :398-400, -1 means "last page"
            $page = $totalPages;
        }
        if ($page < 1) {                 // :402-404
            $page = 1;
        }

        // repository_generic.py:341-343 — created_at desc unless the caller asked otherwise.
        $orderBy = $q['order_by'] ?: 'created_at';
        $direction = $q['order_direction'] === 'asc' ? 'asc' : 'desc';

        $rows = (clone $builder)->orderBy($orderBy, $direction);
        if ($limit !== null) {
            $rows->limit($limit);
        }
        $rows = $rows->offset(($page - 1) * $perPage)->get();

        $out = [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $count,
            'total_pages' => $totalPages,
            'items' => $rows->map($mapper)->values()->all(),
        ];

        $out['next'] = $page >= $totalPages ? null : self::guide($route, $q, $page + 1, $perPage);
        $out['previous'] = $page <= 1 ? null : self::guide($route, $q, $page - 1, $perPage);

        return $out;
    }

    /**
     * pagination.py:60-94. The parameters are camelized and passed through urlencode, so an unset
     * one arrives as the literal string "None" — that is Python's None rendered, not an omission.
     * The order is the field order of RequestQuery then PaginationQuery.
     */
    private static function guide(string $route, array $q, int $page, int $perPage): string
    {
        $params = [
            'orderBy' => $q['order_by'] ?? 'None',
            'orderByNullPosition' => $q['order_by_null_position'] ?? 'None',
            'orderDirection' => $q['order_direction'] ?? 'desc',
            'queryFilter' => $q['query_filter'] ?? 'None',
            'paginationSeed' => $q['pagination_seed'] ?? 'None',
            'page' => $page,
            'perPage' => $perPage,
        ];

        return $route.'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
