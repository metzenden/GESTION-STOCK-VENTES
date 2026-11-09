<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Infrastructure\Models\Category;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/** Arborescence des catégories (L2-01) : chemin matérialisé /id1/id2/, nom unique par parent. */
final class CategoryService
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function create(string $name, ?string $parentId, ?string $actorId): Category
    {
        return $this->db->transaction(function () use ($name, $parentId, $actorId) {
            $parent = $parentId !== null ? $this->find($parentId, 'parent_id') : null;
            $this->assertNameFree($name, $parentId, null);

            $category = new Category(['name' => $name, 'parent_id' => $parentId, 'is_active' => true]);
            $category->id = strtoupper((string) Str::ulid());
            $category->path = ($parent !== null ? $parent->path : '/').$category->id.'/';
            $category->forceFill(['created_by' => $actorId])->save();

            return $category;
        });
    }

    /** @param array{name?: string, parent_id?: string|null, is_active?: bool} $changes */
    public function update(string $id, array $changes, ?string $actorId): Category
    {
        return $this->db->transaction(function () use ($id, $changes, $actorId) {
            $category = Category::query()->lockForUpdate()->findOrFail($id);
            $parentId = array_key_exists('parent_id', $changes) ? $changes['parent_id'] : $category->parent_id;
            $name = $changes['name'] ?? $category->name;

            if ($name !== $category->name || $parentId !== $category->parent_id) {
                $this->assertNameFree($name, $parentId, $category->id);
            }

            if ($parentId !== $category->parent_id) {
                $parent = $parentId !== null ? $this->find($parentId, 'parent_id') : null;

                if ($parent !== null && str_contains($parent->path, '/'.$category->id.'/')) {
                    throw BusinessRuleViolation::invalidField('parent_id', 'Une catégorie ne peut pas être rangée sous elle-même ou sous une de ses sous-catégories.');
                }

                $oldPath = $category->path;
                $newPath = ($parent !== null ? $parent->path : '/').$category->id.'/';

                // Les sous-catégories suivent le déplacement.
                Category::query()->where('path', 'like', $oldPath.'%')->whereKeyNot($category->id)->get()
                    ->each(fn (Category $child) => $child->forceFill(['path' => $newPath.substr($child->path, strlen($oldPath))])->save());

                $category->parent_id = $parentId;
                $category->path = $newPath;
            }

            $category->name = $name;

            if (isset($changes['is_active'])) {
                $category->is_active = $changes['is_active'];
            }

            $category->forceFill(['updated_by' => $actorId])->save();

            return $category;
        });
    }

    public function delete(string $id): void
    {
        $this->db->transaction(function () use ($id) {
            $category = Category::query()->lockForUpdate()->findOrFail($id);

            if (Category::query()->where('parent_id', $category->id)->exists()) {
                throw new BusinessRuleViolation('category_not_empty', 'Catégorie non vide', 'Cette catégorie contient des sous-catégories.', 409);
            }

            if (Product::query()->where('category_id', $category->id)->exists()) {
                throw new BusinessRuleViolation('category_not_empty', 'Catégorie non vide', 'Des produits sont rangés dans cette catégorie.', 409);
            }

            $category->delete();
        });
    }

    private function find(string $id, string $field): Category
    {
        return Category::query()->find($id) ?? throw BusinessRuleViolation::invalidField($field, 'Cette catégorie n\'existe pas.');
    }

    private function assertNameFree(string $name, ?string $parentId, ?string $exceptId): void
    {
        $taken = Category::query()->where('name', $name)
            ->where(fn ($q) => $parentId === null ? $q->whereNull('parent_id') : $q->where('parent_id', $parentId))
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw BusinessRuleViolation::invalidField('name', 'Une catégorie de même nom existe déjà à cet endroit.');
        }
    }
}
