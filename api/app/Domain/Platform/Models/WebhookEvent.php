<?php

declare(strict_types=1);

namespace App\Domain\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Inbound provider webhook, stored before processing and deduplicated by (provider, event_id).
 *
 * @property string $id
 * @property string $provider
 * @property string $event_id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property \Illuminate\Support\Carbon $received_at
 * @property \Illuminate\Support\Carbon|null $processed_at
 * @property string|null $error
 */
final class WebhookEvent extends Model
{
    use HasUlids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
