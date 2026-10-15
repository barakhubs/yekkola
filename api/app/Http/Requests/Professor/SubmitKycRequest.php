<?php

declare(strict_types=1);

namespace App\Http\Requests\Professor;

use App\Domain\Professors\Enums\KycDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SubmitKycRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $file = ['file', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:'.config('yekkola.professors.max_kyc_upload_kb')];

        return [
            'document_type' => ['required', Rule::enum(KycDocumentType::class)],
            'front' => ['required', ...$file],
            'back' => ['nullable', ...$file],
            'selfie' => ['required', 'file', 'mimetypes:image/jpeg,image/png', 'max:'.config('yekkola.professors.max_kyc_upload_kb')],
        ];
    }

    public function documentType(): KycDocumentType
    {
        return KycDocumentType::from((string) $this->input('document_type'));
    }
}
