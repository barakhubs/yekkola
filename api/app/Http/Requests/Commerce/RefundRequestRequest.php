<?php

declare(strict_types=1);

namespace App\Http\Requests\Commerce;

use Illuminate\Foundation\Http\FormRequest;

/** A refund request for one course of a paid order (PRD-05 FR-16). */
final class RefundRequestRequest extends FormRequest
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
            'order_item_id' => ['required', 'ulid'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
