<?php

declare(strict_types=1);

namespace App\Http\Requests\Professor;

use Illuminate\Foundation\Http\FormRequest;

final class UploadAvatarRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'avatar' => ['required', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'dimensions:min_width=200,min_height=200', 'max:'.config('yekkola.professors.max_avatar_upload_kb')],
        ];
    }
}
