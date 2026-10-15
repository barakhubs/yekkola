<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Admin-only professor settings (PRD-02 BR-02): revenue-share override (null = platform default).
 */
final class UpdateProfessorRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'revenue_share_bps' => ['present', 'nullable', 'integer', 'min:0', 'max:10000'],
            'note' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
