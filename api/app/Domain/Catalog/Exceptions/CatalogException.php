<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

final class CatalogException extends DomainException
{
    public static function categoryInUse(): self
    {
        return new self('category.in_use', 409);
    }

    public static function invalidParent(): self
    {
        return new self('category.invalid_parent', 422);
    }

    public static function orderMismatch(): self
    {
        return new self('category.order_mismatch', 422);
    }
}
