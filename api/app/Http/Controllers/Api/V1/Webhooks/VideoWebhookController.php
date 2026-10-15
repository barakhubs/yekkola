<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Domain\Authoring\Jobs\ProcessVideoWebhook;
use App\Domain\Platform\Models\WebhookEvent;
use App\Http\Controllers\Controller;
use App\Integrations\InvalidWebhookSignature;
use App\Integrations\Video\VideoProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;

/**
 * Video provider webhooks (Mux). Verify the signature, store the event once (dedupe by event id),
 * answer fast, and process on the queue. Forged requests are logged at warning level.
 */
final class VideoWebhookController extends Controller
{
    public function __invoke(Request $request, VideoProvider $videos, LoggerInterface $logger): JsonResponse
    {
        try {
            $event = $videos->parseWebhook($request);
        } catch (InvalidWebhookSignature $e) {
            $logger->warning('Rejected video webhook with an invalid signature', ['ip' => $request->ip()]);

            throw $e;
        }

        $stored = WebhookEvent::query()->createOrFirst(
            ['provider' => $videos->name(), 'event_id' => $event->eventId],
            [
                'type' => $event->type->value,
                'payload' => [
                    ...$event->payload,
                    '_type' => $event->type->value,
                    '_asset_id' => $event->assetId,
                    '_upload_id' => $event->uploadId,
                    '_passthrough' => $event->passthrough,
                ],
                'received_at' => now(),
            ],
        );

        if ($stored->wasRecentlyCreated) {
            ProcessVideoWebhook::dispatch($stored->id);
        }

        return new JsonResponse(['received' => true]);
    }
}
