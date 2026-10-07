<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Exceptions\CurrencyMismatch;
use App\Domain\Shared\ValueObjects\Money;

it('adds and subtracts amounts in the same currency', function () {
    $a = Money::of(1_500, Currency::USD);
    $b = Money::of(250, Currency::USD);

    expect($a->plus($b)->amountMinor)->toBe(1_750)
        ->and($a->minus($b)->amountMinor)->toBe(1_250)
        ->and($b->minus($a)->isNegative())->toBeTrue();
});

it('refuses to mix currencies', function () {
    Money::of(100, Currency::USD)->plus(Money::of(100, Currency::CDF));
})->throws(CurrencyMismatch::class);

it('takes a basis-point share rounded down', function () {
    expect(Money::of(999, Currency::USD)->shareBps(7_000)->amountMinor)->toBe(699)
        ->and(Money::of(1_000, Currency::CDF)->shareBps(10_000)->amountMinor)->toBe(1_000)
        ->and(Money::of(1_000, Currency::CDF)->shareBps(0)->isZero())->toBeTrue();
});

it('rejects basis points outside 0–10000', function () {
    Money::of(100, Currency::USD)->shareBps(10_001);
})->throws(InvalidArgumentException::class);

it('allocates so the parts always sum to the whole', function (int $amount, array $ratios) {
    $parts = Money::of($amount, Currency::USD)->allocate($ratios);
    $sum = array_sum(array_map(fn (Money $m) => $m->amountMinor, $parts));

    expect($sum)->toBe($amount)->and($parts)->toHaveCount(count($ratios));
})->with([
    'revenue split 70/30' => [999, [7_000, 3_000]],
    'three equal ways' => [100, [1, 1, 1]],
    'bundle pro-rata' => [2_500, [1_200, 800, 1_000]],
    'negative refund' => [-1_001, [7, 3]],
    'single part' => [42, [1]],
]);

it('gives leftover minor units to the earliest parts', function () {
    $parts = Money::of(100, Currency::USD)->allocate([1, 1, 1]);

    expect(array_map(fn (Money $m) => $m->amountMinor, $parts))->toBe([34, 33, 33]);
});

it('serialises as amount_minor and currency', function () {
    expect(json_encode(Money::of(1_234, Currency::CDF)))->toBe('{"amount_minor":1234,"currency":"CDF"}');
});

it('compares by amount and currency', function () {
    expect(Money::of(5, Currency::USD)->equals(Money::of(5, Currency::USD)))->toBeTrue()
        ->and(Money::of(5, Currency::USD)->equals(Money::of(5, Currency::CDF)))->toBeFalse()
        ->and(Money::of(10, Currency::USD)->greaterThanOrEqual(Money::of(10, Currency::USD)))->toBeTrue();
});
