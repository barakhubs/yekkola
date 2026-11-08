<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationType;
use App\Http\Controllers\Api\V1\Account\NotificationController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Channel switches for events users may turn off (PRD-10 FR-05, BR-02). */
final class NotificationPreferencesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array', 'min:1', 'max:50'],
            'preferences.*.type' => ['required', Rule::enum(NotificationType::class)],
            'preferences.*.channel' => ['required', Rule::enum(NotificationChannel::class)],
            'preferences.*.enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ((array) $this->input('preferences', []) as $i => $item) {
                $type = NotificationType::tryFrom((string) ($item['type'] ?? ''));
                $channel = NotificationChannel::tryFrom((string) ($item['channel'] ?? ''));
                if ($type === null || $channel === null) {
                    continue;
                }
                if (! $type->canDisable() || ! in_array($channel, NotificationController::settableChannels($type), true)) {
                    $validator->errors()->add("preferences.{$i}.type", __('validation.notification_not_optional'));
                }
            }
        }];
    }
}
