<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Jobs;

use App\Domain\Authoring\Actions\SyncMediaAsset;
use App\Domain\Platform\Models\WebhookEvent;
use App\Integrations\Video\VideoEventType;
use App\Integrations\Video\VideoWebhookEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Processes one stored video-provider webhook (on the `media` queue). Safe to run twice.
 */
final class ProcessVideoWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 600];

    public function __construct(public readonly string $webhookEventId)
    {
        $this->onQueue('media');
    }

    public function handle(SyncMediaAsset $sync): void
    {
        $stored = WebhookEvent::query()->find($this->webhookEventId);
        if ($stored === null || $stored->processed_at !== null) {
            return;
        }

        $p = $stored->payload;
        $sync->fromEvent(new VideoWebhookEvent(
            eventId: $stored->event_id,
            type: VideoEventType::from((string) ($p['_type'] ?? VideoEventType::Other->value)),
            assetId: $p['_asset_id'] ?? null,
            uploadId: $p['_upload_id'] ?? null,
            passthrough: $p['_passthrough'] ?? null,
            payload: $p,
        ));

        $stored->forceFill(['processed_at' => now(), 'error' => null])->save();
    }

    public function failed(Throwable $e): void
    {
        WebhookEvent::query()->whereKey($this->webhookEventId)->update(['error' => mb_substr($e->getMessage(), 0, 2000)]);
    }
}
