<?php

declare(strict_types=1);

namespace App\Http\Requests\Authoring;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Course cover: 16:9, at least 1280x720 (PRD-03 FR-02).
 */
final class CoverRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cover' => ['required', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'dimensions:min_width=1280,min_height=720,ratio=16/9', 'max:5120'],
        ];
    }
}
