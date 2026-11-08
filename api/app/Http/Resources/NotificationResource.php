<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * An in-app notification (PRD-10 FR-03), its text rendered in the reader's language from the stored key and
 * params; `link` is the web path to open.
 *
 * @mixin DatabaseNotification
 */
final class NotificationResource extends JsonResource
{
    /**
     * @return array{id: string, type: string, title: string, body: string, link: string|null, read_at: string|null, created_at: string}
     */
    public function toArray(Request $request): array
    {
        /** @var array{type?: string, key?: string, params?: array<string, string>, link?: string|null} $data */
        $data = $this->data;
        $key = (string) ($data['key'] ?? '');
        $params = $data['params'] ?? [];

        return [
            'id' => (string) $this->id,
            'type' => (string) ($data['type'] ?? ''),
            'title' => (string) __("notifications.{$key}.title", $params),
            'body' => (string) __("notifications.{$key}.body", $params),
            'link' => isset($data['link']) ? (string) $data['link'] : null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => (string) $this->created_at?->toIso8601String(),
        ];
    }
}
