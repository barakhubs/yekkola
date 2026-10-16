<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\QuoteLine;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Settings\CommerceSettings;
use Illuminate\Database\ConnectionInterface;

/**
 * Creates an order from a fresh server-side quote (PRD-05 FR-03). The buyer's `Idempotency-Key` is stored
 * on the row: the same key returns the same order. Items snapshot prices and revenue shares (BR-03), and
 * the order expires after the payment timeout setting.
 */
final class CreateOrder
{
    /** Unambiguous characters for order numbers (no 0/O, 1/I/L). */
    private const NUMBER_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly QuoteOrder $quote,
        private readonly CommerceSettings $settings,
    ) {}

    /**
     * @param  list<string>  $courseIds
     */
    public function handle(User $buyer, array $courseIds, string $idempotencyKey): Order
    {
        $existing = Order::query()->where('buyer_id', $buyer->id)->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $quote = $this->quote->handle($buyer, $courseIds);

        return $this->db->transaction(function () use ($buyer, $quote, $idempotencyKey): Order {
            $order = Order::query()->createOrFirst(
                ['buyer_id' => $buyer->id, 'idempotency_key' => $idempotencyKey],
                [
                    'number' => $this->number(),
                    'beneficiary_id' => $buyer->id,
                    'status' => OrderStatus::Pending,
                    'currency' => $quote->currency->value,
                    'subtotal_minor' => $quote->subtotal()->amountMinor,
                    'discount_minor' => $quote->discount()->amountMinor,
                    'total_minor' => $quote->total()->amountMinor,
                    'expires_at' => now()->addMinutes($this->settings->order_payment_timeout_minutes),
                ],
            );

            if (! $order->wasRecentlyCreated) {
                return $order; // concurrent request with the same key won
            }

            $order->items()->createMany(array_map(fn (QuoteLine $line) => [
                'course_id' => $line->course->id,
                'professor_profile_id' => $line->course->professor_profile_id,
                'title_snapshot' => (string) $line->course->liveVersion?->title,
                'list_price_minor' => $line->listPrice->amountMinor,
                'discount_minor' => $line->discount->amountMinor,
                'price_minor' => $line->price()->amountMinor,
                'revenue_share_bps' => $line->revenueShareBps,
                'currency' => $quote->currency->value,
            ], $quote->lines));

            return $order;
        });
    }

    /** e.g. YK-7F3K9Q2M: short enough to read over the phone to support. */
    private function number(): string
    {
        do {
            $suffix = '';
            for ($i = 0; $i < 8; $i++) {
                $suffix .= self::NUMBER_ALPHABET[random_int(0, strlen(self::NUMBER_ALPHABET) - 1)];
            }
            $number = 'YK-'.$suffix;
        } while (Order::query()->where('number', $number)->exists());

        return $number;
    }
}
