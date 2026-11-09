<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\CategoryService;
use App\Modules\Catalog\Http\Resources\CatalogPresenter;
use App\Modules\Catalog\Infrastructure\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CategoryController
{
    public function __construct(private readonly CategoryService $categories) {}

    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => Category::query()->orderBy('path')->get()->map(CatalogPresenter::category(...))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'parent_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);

        $category = $this->categories->create($validated['name'], $validated['parent_id'] ?? null, $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => CatalogPresenter::category($category)], 201);
    }

    public function update(Request $request, string $category): JsonResponse
    {
        $category = Category::query()->findOrFail($category)->id;
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:120'],
            'parent_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return new JsonResponse(['data' => CatalogPresenter::category($this->categories->update(
            $category, $validated, $request->user()?->getAuthIdentifier(),
        ))]);
    }

    public function destroy(string $category): JsonResponse
    {
        $this->categories->delete(Category::query()->findOrFail($category)->id);

        return new JsonResponse(null, 204);
    }
}
