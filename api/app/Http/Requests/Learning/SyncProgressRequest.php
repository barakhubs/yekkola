<?php

declare(strict_types=1);

namespace App\Http\Requests\Learning;

use Illuminate\Foundation\Http\FormRequest;

/** A batch of progress updates (PRD-06 FR-05). */
final class SyncProgressRequest extends FormRequest
{
    public const MAX_ITEMS = 100;

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
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*.lesson_uid' => ['required', 'string', 'size:26'],
            'items.*.position_seconds' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:86400'],
            'items.*.completed' => ['sometimes', 'nullable', 'boolean'],
            'items.*.client_updated_at' => ['sometimes', 'nullable', 'date_format:Y-m-d\\TH:i:sP,Y-m-d\\TH:i:s\\Z,Y-m-d\\TH:i:s.vP,Y-m-d\\TH:i:s.v\\Z'],
        ];
    }

    /**
     * @return list<array{lesson_uid: string, position_seconds?: int|null, completed?: bool|null, client_updated_at?: string|null}>
     */
    public function items(): array
    {
        /** @var list<array{lesson_uid: string, position_seconds?: int|null, completed?: bool|null, client_updated_at?: string|null}> */
        return array_values((array) $this->validated('items'));
    }
}
