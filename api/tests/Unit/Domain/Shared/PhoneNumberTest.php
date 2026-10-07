<?php

declare(strict_types=1);

use App\Domain\Shared\Exceptions\InvalidPhoneNumber;
use App\Domain\Shared\ValueObjects\PhoneNumber;

it('normalises DRC mobile numbers to E.164', function (string $input) {
    expect(PhoneNumber::fromString($input)->e164)->toBe('+243812345678');
})->with([
    'national with leading zero' => '0812345678',
    'international' => '+243812345678',
    'with spaces' => '+243 81 234 5678',
    'with dashes' => '081-234-5678',
]);

it('accepts mobile numbers from other countries', function () {
    expect(PhoneNumber::fromString('+256772123456')->e164)->toBe('+256772123456')
        ->and(PhoneNumber::fromString('+256772123456')->countryCode())->toBe(256);
});

it('rejects invalid input', function (string $input) {
    PhoneNumber::fromString($input);
})->throws(InvalidPhoneNumber::class)->with([
    'letters' => 'abc',
    'too short' => '0812',
    'empty' => '',
]);

it('masks all but the first and last three digits', function () {
    expect(PhoneNumber::fromString('+243812345678')->masked())->toBe('+243 8•• ••• 678');
});

it('compares and stringifies by E.164', function () {
    $a = PhoneNumber::fromString('0812345678');

    expect($a->equals(PhoneNumber::fromString('+243812345678')))->toBeTrue()
        ->and((string) $a)->toBe('+243812345678')
        ->and(json_encode($a))->toBe('"+243812345678"');
});
