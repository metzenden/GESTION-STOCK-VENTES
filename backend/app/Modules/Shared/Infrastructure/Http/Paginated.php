<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Http;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Réponse de liste normalisée : { data, meta: { page, per_page, total, last_page } } (docs/05 §1.4). */
final class Paginated
{
    public const MAX_PER_PAGE = 100;

    /**
     * @template T
     *
     * @param  LengthAwarePaginator<int, T>  $page
     * @param  callable(T): array<string, mixed>  $map
     */
    public static function response(LengthAwarePaginator $page, callable $map): JsonResponse
    {
        return new JsonResponse([
            'data' => array_map($map, $page->items()),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => max(1, $page->lastPage()),
            ],
        ]);
    }

    /** @return array{page: int, per_page: int} */
    public static function input(Request $request): array
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        return ['page' => (int) ($validated['page'] ?? 1), 'per_page' => (int) ($validated['per_page'] ?? 25)];
    }
}
