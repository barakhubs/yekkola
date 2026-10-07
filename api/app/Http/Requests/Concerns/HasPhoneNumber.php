<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Domain\Shared\ValueObjects\PhoneNumber;

/**
 * Parses the `phone` input into a PhoneNumber (invalid numbers render as `phone.invalid`).
 */
trait HasPhoneNumber
{
    public function phone(): PhoneNumber
    {
        return PhoneNumber::fromString((string) $this->input('phone'));
    }
}
