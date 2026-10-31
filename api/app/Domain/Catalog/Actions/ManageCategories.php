<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Authoring\Models\Course;
use App\Domain\Catalog\Exceptions\CatalogException;
use App\Domain\Catalog\Models\Category;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\RecordAudit;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * The category tree from the back office (PRD-09 FR-09): two levels (a category and its sub-categories), names and
 * descriptions in fr and en, an icon, visibility, and the order within each level. A category with courses or
 * sub-categories can't be deleted (hide it instead). Every change is audited.
 */
final class ManageCategories
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly RecordAudit $recordAudit,
    ) {}

    /**
     * @param  array{parent_id?: string|null, slug?: string|null, name: array{fr: string, en: string}, description?: array{fr?: string|null, en?: string|null}|null, icon?: string|null, is_visible: bool}  $data
     */
    public function create(array $data, User $staff): Category
    {
        return $this->db->transaction(function () use ($data, $staff): Category {
            $parentId = $data['parent_id'] ?? null;
            $this->assertParent($parentId, null);

            $category = new Category;
            $category->fill([
                'parent_id' => $parentId,
                'slug' => $this->slug($data['slug'] ?? null, $data['name']['fr']),
                'icon' => $data['icon'] ?? null,
                'is_visible' => $data['is_visible'],
                'position' => (int) Category::query()->where('parent_id', $parentId)->max('position') + 1,
            ]);
            $category->setTranslations('name', $data['name']);
            $category->setTranslations('description', array_filter($data['description'] ?? [], fn ($v) => $v !== null && $v !== ''));
            $category->save();

            $this->recordAudit->handle('category.created', $category, ['slug' => $category->slug], causer: $staff);

            return $category;
        });
    }

    /**
     * @param  array{parent_id?: string|null, slug?: string|null, name: array{fr: string, en: string}, description?: array{fr?: string|null, en?: string|null}|null, icon?: string|null, is_visible: bool}  $data
     */
    public function update(Category $category, array $data, User $staff): Category
    {
        return $this->db->transaction(function () use ($category, $data, $staff): Category {
            $category = Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($category);

            $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $category->parent_id;
            if ($parentId !== $category->parent_id) {
                $this->assertParent($parentId, $category);
                $category->position = (int) Category::query()->where('parent_id', $parentId)->max('position') + 1;
            }

            $category->fill([
                'parent_id' => $parentId,
                'slug' => ($data['slug'] ?? null) !== null && $data['slug'] !== $category->slug ? $this->slug($data['slug'], $data['name']['fr']) : $category->slug,
                'icon' => $data['icon'] ?? null,
                'is_visible' => $data['is_visible'],
            ]);
            $category->setTranslations('name', $data['name']);
            $category->setTranslations('description', array_filter($data['description'] ?? [], fn ($v) => $v !== null && $v !== ''));
            $category->save();

            $after = $this->snapshot($category);
            if ($before !== $after) {
                $this->recordAudit->handle('category.updated', $category, ['before' => $before, 'after' => $after], causer: $staff);
            }

            return $category;
        });
    }

    public function delete(Category $category, User $staff): void
    {
        $this->db->transaction(function () use ($category, $staff): void {
            $category = Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();
            if (Category::query()->where('parent_id', $category->id)->exists() || Course::withTrashed()->where('category_id', $category->id)->exists()) {
                throw CatalogException::categoryInUse();
            }
            $this->recordAudit->handle('category.deleted', $category, $this->snapshot($category), causer: $staff);
            $category->delete();
        });
    }

    /**
     * Sets the order of one level: the categories under `$parentId` (null: the top level), in the order given.
     *
     * @param  list<string>  $ids  Exactly that level's categories.
     */
    public function reorder(?string $parentId, array $ids, User $staff): void
    {
        $this->db->transaction(function () use ($parentId, $ids, $staff): void {
            $level = Category::query()->where('parent_id', $parentId)->lockForUpdate()->pluck('id')->all();
            sort($level);
            $given = $ids;
            sort($given);
            if ($level !== $given) {
                throw CatalogException::orderMismatch();
            }

            foreach ($ids as $position => $id) {
                Category::query()->whereKey($id)->update(['position' => $position + 1]);
            }
            // One save so listeners (index, web pages) see the change.
            Category::query()->whereKey($ids[0] ?? null)->first()?->touch();

            $this->recordAudit->handle('category.reordered', properties: ['parent_id' => $parentId, 'ids' => $ids], causer: $staff);
        });
    }

    /** Only two levels: a parent must exist, be top-level, and not be the category itself or one of its children. */
    private function assertParent(?string $parentId, ?Category $category): void
    {
        if ($parentId === null) {
            return;
        }
        $parent = Category::query()->find($parentId);
        if ($parent === null || $parent->parent_id !== null || $parentId === $category?->id) {
            throw CatalogException::invalidParent();
        }
        if ($category !== null && Category::query()->where('parent_id', $category->id)->exists()) {
            throw CatalogException::invalidParent(); // it has sub-categories: it must stay top-level
        }
    }

    private function slug(?string $wanted, string $name): string
    {
        $base = Str::slug($wanted ?? $name) ?: 'categorie';
        $slug = $base;
        for ($i = 2; Category::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Category $category): array
    {
        return [
            'parent_id' => $category->parent_id,
            'slug' => $category->slug,
            'name' => $category->getTranslations('name'),
            'description' => $category->getTranslations('description'),
            'icon' => $category->icon,
            'is_visible' => $category->is_visible,
        ];
    }
}
