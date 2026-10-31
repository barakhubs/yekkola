<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class CategoryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** A top-level category to sit under (two levels at most); null for the top level. */
            'parent_id' => ['nullable', 'string', 'exists:categories,id'],
            /** Generated from the French name when left out; made unique. */
            'slug' => ['nullable', 'string', 'max:80', 'alpha_dash'],
            'name' => ['required', 'array'],
            'name.fr' => ['required', 'string', 'min:2', 'max:80'],
            'name.en' => ['required', 'string', 'min:2', 'max:80'],
            'description' => ['nullable', 'array'],
            'description.fr' => ['nullable', 'string', 'max:500'],
            'description.en' => ['nullable', 'string', 'max:500'],
            /** A Lucide icon name. */
            'icon' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/'],
            'is_visible' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array{parent_id?: string|null, slug?: string|null, name: array{fr: string, en: string}, description?: array{fr?: string|null, en?: string|null}|null, icon?: string|null, is_visible: bool}
     */
    public function categoryData(): array
    {
        /** @var array{parent_id?: string|null, slug?: string|null, name: array{fr: string, en: string}, description?: array{fr?: string|null, en?: string|null}|null, icon?: string|null, is_visible: bool} $data */
        $data = $this->validated();

        return $data;
    }
}
