<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Platform\Enums\BrandAsset;
use Illuminate\Foundation\Http\FormRequest;

/** A brand image upload (multipart `file`); each image has its own type, size and dimension rules. */
final class BrandAssetRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $asset = $this->route('asset');

        return [
            'file' => $asset instanceof BrandAsset ? $asset->rules() : ['required', 'image'],
        ];
    }
}
