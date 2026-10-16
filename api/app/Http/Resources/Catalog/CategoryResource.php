<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalog;

use App\Domain\Catalog\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A category in the public tree (PRD-04 FR-02), named in the request locale. `course_count` counts listed
 * courses, including those in subcategories.
 *
 * @mixin Category
 */
final class CategoryResource extends JsonResource
{
    /** @param array<string, int> $counts listed courses per category id */
    public function __construct(Category $category, private readonly array $counts = [])
    {
        parent::__construct($category);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $children = $this->relationLoaded('children') ? $this->children : collect();
        $own = $this->counts[$this->id] ?? 0;

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
            'course_count' => $own + $children->sum(fn (Category $c) => $this->counts[$c->id] ?? 0),
            'children' => $children->map(fn (Category $c) => (new self($c, $this->counts))->toArray($request))->values()->all(),
        ];
    }
}
