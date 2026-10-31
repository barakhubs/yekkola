<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A category as staff edit it (PRD-09 FR-09): both languages, visibility, place in the tree, and what uses it.
 *
 * @mixin Category
 */
final class AdminCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'slug' => $this->slug,
            'name' => $this->translated('name'),
            'description' => $this->translated('description'),
            'icon' => $this->icon,
            'position' => $this->position,
            'is_visible' => $this->is_visible,
            /** Courses filed directly under this category. */
            'course_count' => (int) $this->getAttribute('courses_count'),
            'children_count' => (int) $this->getAttribute('children_count'),
            /** Deletion is possible only when nothing uses it. */
            'can_delete' => $this->canDelete(),
        ];
    }

    private function canDelete(): bool
    {
        return (int) $this->getAttribute('courses_count') === 0 && (int) $this->getAttribute('children_count') === 0;
    }

    /**
     * @return array{fr: string|null, en: string|null}
     */
    private function translated(string $field): array
    {
        $values = $this->getTranslations($field);

        return [
            /** @var string|null */
            'fr' => isset($values['fr']) ? (string) $values['fr'] : null,
            /** @var string|null */
            'en' => isset($values['en']) ? (string) $values['en'] : null,
        ];
    }
}
