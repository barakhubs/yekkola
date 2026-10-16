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
}
