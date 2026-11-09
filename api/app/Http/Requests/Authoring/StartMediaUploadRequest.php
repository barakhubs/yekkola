<?php

declare(strict_types=1);

namespace App\Http\Requests\Authoring;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Start a video/audio upload (PRD-03 FR-04/05): the file's size and type. The size limit per kind is a
 * platform setting, checked by the action.
 */
final class StartMediaUploadRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'size_bytes' => ['required', 'integer', 'min:1'],
            'content_type' => ['required', 'string', 'max:100', 'regex:/^[a-z]+\/[A-Za-z0-9.+-]+$/'],
        ];
    }
}
