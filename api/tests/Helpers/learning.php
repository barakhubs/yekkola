<?php

declare(strict_types=1);

use App\Domain\Learning\Models\Enrollment;

/**
 * Links the free enrollment to an order item, as a purchase would (order rows built directly: the payment
 * flow has its own tests).
 */
function paidOrderItemFor(Enrollment $enrollment): string
{
    $order = App\Domain\Commerce\Models\Order::query()->forceCreate([
        'number' => 'T-'.Illuminate\Support\Str::random(8),
        'buyer_id' => $enrollment->user_id,
        'beneficiary_id' => $enrollment->user_id,
        'status' => 'paid',
        'currency' => 'USD',
        'subtotal_minor' => 1_500,
        'discount_minor' => 0,
        'total_minor' => 1_500,
        'idempotency_key' => 'test',
        'expires_at' => now(),
        'paid_at' => now(),
    ]);
    $item = $order->items()->forceCreate([
        'order_id' => $order->id,
        'course_id' => $enrollment->course_id,
        'professor_profile_id' => App\Domain\Authoring\Models\Course::query()->findOrFail($enrollment->course_id)->professor_profile_id,
        'title_snapshot' => 'Algèbre',
        'list_price_minor' => 1_500,
        'price_minor' => 1_500,
        'currency' => 'USD',
        'revenue_share_bps' => 7_000,
    ]);
    $enrollment->forceFill(['order_item_id' => $item->id])->save();

    return $item->id;
}
