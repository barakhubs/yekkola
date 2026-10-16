<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Domain\Commerce\Jobs\ProcessPaymentEvent;
use App\Domain\Commerce\Models\PaymentEvent;
use App\Http\Controllers\Controller;
use App\Integrations\InvalidWebhookSignature;
use App\Integrations\Payments\PaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;

/**
 * Payment gateway webhooks (PRD-05 FR-07): verify the signature, store the event once (deduplicated by the
 * gateway's event id), and process it on the `payments` queue. Acknowledged fast; never acted on directly.
 */
final class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $gateway, PaymentGateway $payments, LoggerInterface $logger): JsonResponse
    {
        abort_if($gateway !== $payments->name(), 404);

        try {
            $event = $payments->parseWebhook($request);
        } catch (InvalidWebhookSignature $e) {
            $logger->warning('Rejected payment webhook with an invalid signature', ['gateway' => $gateway, 'ip' => $request->ip()]);

            throw $e;
        }

        $stored = PaymentEvent::query()->createOrFirst(
            ['gateway' => $payments->name(), 'event_id' => $event->eventId],
            [
                'kind' => $event->kind->value,
                'reference' => mb_substr($event->reference, 0, 64),
                'payload' => $event->payload,
                'received_at' => now(),
            ],
        );

        if ($stored->wasRecentlyCreated) {
            ProcessPaymentEvent::dispatch($stored->id)->afterCommit();
        }

        return new JsonResponse(['received' => true]);
    }
}
