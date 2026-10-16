<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Authoring\Enums\Audience;
use App\Domain\Authoring\Enums\CourseLevel;
use App\Domain\Shared\Enums\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BrowseCoursesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'price' => ['sometimes', 'nullable', Rule::in(['free', 'paid'])],
            'currency' => ['sometimes', 'nullable', Rule::enum(Currency::class)],
            'language' => ['sometimes', 'nullable', Rule::in(['fr', 'en'])],
            'level' => ['sometimes', 'nullable', Rule::enum(CourseLevel::class)],
            'audience' => ['sometimes', 'nullable', Rule::enum(Audience::class)],
            'sort' => ['sometimes', 'nullable', Rule::in(['relevance', 'popular', 'newest', 'rating', 'price_asc', 'price_desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{q?: string|null, category?: string|null, price?: string|null, currency?: string|null, language?: string|null, level?: string|null, audience?: string|null, sort?: string|null}
     */
    public function filters(): array
    {
        /** @var array{q?: string|null, category?: string|null, price?: string|null, currency?: string|null, language?: string|null, level?: string|null, audience?: string|null, sort?: string|null} */
        return $this->safe()->only(['q', 'category', 'price', 'currency', 'language', 'level', 'audience', 'sort']);
    }
}
