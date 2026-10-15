<?php

declare(strict_types=1);

namespace App\Http\Requests\Professor;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A single credential file (PDF or image) for an application.
 */
final class UploadFileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:'.config('yekkola.professors.max_credential_upload_kb')],
        ];
    }
}
