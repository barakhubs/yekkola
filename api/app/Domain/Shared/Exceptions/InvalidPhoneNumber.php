<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

final class InvalidPhoneNumber extends DomainException
{
    public static function make(): self
    {
        return new self(errorCode: 'phone.invalid', status: 422);
    }
}
