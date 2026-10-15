<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Authoring\Models\Course;
use App\Domain\Catalog\Models\Category;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The public catalogue and search (PRD-04 FR-02, FR-03). Meilisearch only ranks the text matches
 * (typo-tolerant); every filter and sort runs in Postgres on listed courses, so browsing and search
 * share one implementation. When the search service is down, a plain database match takes over.
 */
final class BrowseCourses
{
    /** Search hits considered per query (Meilisearch's default pagination ceiling). */
    private const MAX_HITS = 1_000;

    public function __construct(private readonly LoggerInterface $log) {}

    /**
     * @param  array{q?: string|null, category?: string|null, price?: string|null, currency?: string|null, language?: string|null, level?: string|null, audience?: string|null, sort?: string|null}  $filters
     * @return LengthAwarePaginator<int, Course>
     */
    public function handle(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Course::query()->listed()->with(self::cardRelations());
        $q = trim((string) ($filters['q'] ?? ''));
        $rankedIds = null;

        if ($q !== '') {
            $rankedIds = $this->search($q);
            $rankedIds === null
                ? $this->matchInDatabase($query, $q)
                : $query->whereIn('id', $rankedIds);
        }

        $this->filter($query, $filters);
        $this->sort($query, $filters['sort'] ?? null, $rankedIds);

        return $query->paginate($perPage);
    }

    /**
     * Relations every course card needs (PRD-04 FR-04).
     *
     * @return list<string>
     */
    public static function cardRelations(): array
    {
        return ['liveVersion.media', 'professor.media', 'category'];
    }

    /**
     * @return list<string>|null Ranked ids, or null when the search service is unavailable.
     */
    private function search(string $q): ?array
    {
        try {
            return array_values(array_map('strval', Course::search($q)->take(self::MAX_HITS)->keys()->all()));
        } catch (Throwable $e) {
            $this->log->warning('Course search unavailable, falling back to the database', ['exception' => $e::class]);

            return null;
        }
    }

    /**
     * @param  Builder<Course>  $query
     */
    private function matchInDatabase(Builder $query, string $q): void
    {
        $like = '%'.addcslashes($q, '%_\\').'%';

        $query->where(fn (Builder $w) => $w
            ->whereHas('liveVersion', fn (Builder $v) => $v->where('title', 'ilike', $like)->orWhere('subtitle', 'ilike', $like))
            ->orWhereHas('professor', fn (Builder $p) => $p->where('display_name', 'ilike', $like)));
    }

    /**
     * @param  Builder<Course>  $query
     * @param  array<string, mixed>  $filters
     */
    private function filter(Builder $query, array $filters): void
    {
        if (filled($filters['category'] ?? null)) {
            $category = Category::query()->where('slug', $filters['category'])->first();
            $ids = $category === null ? [] : [$category->id, ...$category->children()->pluck('id')->all()];
            $query->whereIn('category_id', $ids);
        }

        match ($filters['price'] ?? null) {
            'free' => $query->where('is_free', true),
            'paid' => $query->where('is_free', false),
            default => null,
        };

        foreach (['currency', 'language', 'level'] as $column) {
            if (filled($filters[$column] ?? null)) {
                $query->where($column, $filters[$column]);
            }
        }

        if (filled($filters['audience'] ?? null)) {
            $query->whereJsonContains('audience', $filters['audience']);
        }
    }

    /**
     * Prices are never converted: sorting by price groups by currency, free courses first.
     *
     * @param  Builder<Course>  $query
     * @param  list<string>|null  $rankedIds
     */
    private function sort(Builder $query, ?string $sort, ?array $rankedIds): void
    {
        $sort ??= $rankedIds !== null ? 'relevance' : 'popular';

        match ($sort) {
            'relevance' => $rankedIds === null || $rankedIds === []
                ? $query->orderByDesc('enrollment_count')
                : $query->orderByRaw('array_position(?::text[], id::text)', ['{'.implode(',', $rankedIds).'}']),
            'newest' => $query->orderByDesc('published_at'),
            'rating' => $query->orderByRaw('rating_count >= ? desc', [Course::MIN_REVIEWS_FOR_RATING])->orderByDesc('rating_avg'),
            'price_asc' => $query->orderByDesc('is_free')->orderBy('currency')->orderBy('price_minor'),
            'price_desc' => $query->orderBy('is_free')->orderBy('currency')->orderByDesc('price_minor'),
            default => $query->orderByDesc('enrollment_count'),
        };

        $query->orderByDesc('published_at')->orderBy('id');
    }
}
