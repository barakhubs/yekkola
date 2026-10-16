<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Checkout errors (PRD-05). Each has a stable `code` rendered by the API error envelope.
 */
final class CommerceException extends DomainException
{
    public static function courseUnavailable(): self
    {
        return new self('order.course_unavailable', 422);
    }

    public static function freeCourse(): self
    {
        return new self('order.free_course', 422);
    }

    public static function mixedCurrency(): self
    {
        return new self('order.mixed_currency', 422);
    }

    public static function nothingToBuy(): self
    {
        return new self('order.nothing_to_buy', 422);
    }

    public static function currencyUnavailable(): self
    {
        return new self('order.currency_unavailable', 422);
    }

    public static function openOrderExists(string $orderId): self
    {
        return new self('order.open_order_exists', 409, ['order_id' => $orderId]);
    }

    public static function separatePayerDisabled(): self
    {
        return new self('order.separate_payer_disabled', 403);
    }

    public static function beneficiaryUnavailable(): self
    {
        return new self('order.beneficiary_unavailable', 422);
    }

    public static function payerRequired(): self
    {
        return new self('payment.payer_required', 422);
    }

    public static function orderExpired(): self
    {
        return new self('order.expired', 409);
    }

    public static function notPayable(): self
    {
        return new self('order.not_payable', 409);
    }

    public static function paymentInProgress(): self
    {
        return new self('payment.in_progress', 409);
    }

    public static function railUnavailable(): self
    {
        return new self('payment.rail_unavailable', 422);
    }

    public static function amountOutOfRange(): self
    {
        return new self('payment.amount_out_of_range', 422);
    }

    public static function couponInvalid(): self
    {
        return new self('coupon.invalid', 422);
    }

    public static function couponNotApplicable(): self
    {
        return new self('coupon.not_applicable', 422);
    }

    public static function couponExhausted(): self
    {
        return new self('coupon.exhausted', 422);
    }

    public static function couponAlreadyUsed(): self
    {
        return new self('coupon.already_used', 422);
    }
}
