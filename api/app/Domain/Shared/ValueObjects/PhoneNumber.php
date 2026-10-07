<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Exceptions\InvalidPhoneNumber;
use JsonSerializable;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;
use Stringable;

/**
 * A validated mobile phone number in E.164 form. DRC (+243) is the default region,
 * but numbers from other countries are accepted.
 */
final readonly class PhoneNumber implements JsonSerializable, Stringable
{
    private function __construct(public string $e164) {}

    /**
     * @throws InvalidPhoneNumber
     */
    public static function fromString(string $input, string $defaultRegion = 'CD'): self
    {
        $util = PhoneNumberUtil::getInstance();

        try {
            $number = $util->parse(trim($input), $defaultRegion);
        } catch (NumberParseException) {
            throw InvalidPhoneNumber::make();
        }

        if (! $util->isValidNumber($number)) {
            throw InvalidPhoneNumber::make();
        }

        $type = $util->getNumberType($number);
        if (! in_array($type, [PhoneNumberType::MOBILE, PhoneNumberType::FIXED_LINE_OR_MOBILE], true)) {
            throw InvalidPhoneNumber::make();
        }

        return new self($util->format($number, PhoneNumberFormat::E164));
    }

    public function countryCode(): int
    {
        return (int) PhoneNumberUtil::getInstance()->parse($this->e164)->getCountryCode();
    }

    /**
     * Masked form for watermarks, logs, and anything shown to other people,
     * e.g. "+243 8•• ••• 123". Keeps the country code, first digit, and last three digits.
     * Use '*' in SMS: "•" is not in the GSM-7 alphabet (it would force 70-char UCS-2 messages).
     */
    public function masked(string $maskChar = '•'): string
    {
        $util = PhoneNumberUtil::getInstance();
        $number = $util->parse($this->e164);
        $national = (string) $number->getNationalNumber();

        $hidden = str_repeat($maskChar, max(0, strlen($national) - 4));
        $body = $national[0].$hidden.substr($national, -3);
        $groups = array_map(implode(...), array_chunk(mb_str_split($body), 3));

        return '+'.$number->getCountryCode().' '.implode(' ', $groups);
    }

    public function equals(self $other): bool
    {
        return $this->e164 === $other->e164;
    }

    public function __toString(): string
    {
        return $this->e164;
    }

    public function jsonSerialize(): string
    {
        return $this->e164;
    }
}
