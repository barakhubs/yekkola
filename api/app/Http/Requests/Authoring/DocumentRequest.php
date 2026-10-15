<?php

declare(strict_types=1);

namespace App\Http\Requests\Authoring;

use App\Domain\Platform\Settings\CatalogSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PDF lesson (PRD-03 FR-06), size limit from settings.
 */
final class DocumentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document' => ['required', 'file', 'mimetypes:application/pdf', 'max:'.(app(CatalogSettings::class)->max_document_upload_mb * 1024)],
        ];
    }
}
