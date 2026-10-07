<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Exceptions\CurrencyMismatch;
use InvalidArgumentException;
use JsonSerializable;

/**
 * An amount in integer minor units with its currency. Immutable; arithmetic refuses mixed currencies.
 */
final readonly class Money implements JsonSerializable
{
    private function __construct(
        public int $amountMinor,
        public Currency $currency,
    ) {}

    public static function of(int $amountMinor, Currency $currency): self
    {
        return new self($amountMinor, $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor + $other->amountMinor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor - $other->amountMinor, $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->amountMinor, $this->currency);
    }

    /**
     * Share of this amount in basis points (10000 = 100%), rounded down.
     * Use allocate() when the parts must add up exactly to the whole.
     */
    public function shareBps(int $bps): self
    {
        if ($bps < 0 || $bps > 10_000) {
            throw new InvalidArgumentException('Basis points must be between 0 and 10000.');
        }

        return new self(intdiv($this->amountMinor * $bps, 10_000), $this->currency);
    }

    /**
     * Split by integer ratios so the parts always sum to the original amount
     * (largest-remainder method; leftovers go to the earliest parts).
     *
     * @param  list<int>  $ratios
     * @return list<self>
     */
    public function allocate(array $ratios): array
    {
        $total = array_sum($ratios);

        if ($ratios === [] || $total <= 0 || min($ratios) < 0) {
            throw new InvalidArgumentException('Ratios must be non-negative and sum to more than zero.');
        }

        $sign = $this->amountMinor < 0 ? -1 : 1;
        $amount = abs($this->amountMinor);
        $parts = [];
        $remainders = [];

        foreach ($ratios as $i => $ratio) {
            $parts[$i] = intdiv($amount * $ratio, $total);
            $remainders[$i] = ($amount * $ratio) % $total;
        }

        $leftover = $amount - array_sum($parts);
        arsort($remainders);

        foreach (array_keys($remainders) as $i) {
            if ($leftover-- <= 0) {
                break;
            }
            $parts[$i]++;
        }

        ksort($parts);

        return array_values(array_map(fn (int $part) => new self($sign * $part, $this->currency), $parts));
    }

    public function isZero(): bool
    {
        return $this->amountMinor === 0;
    }

    public function isPositive(): bool
    {
        return $this->amountMinor > 0;
    }

    public function isNegative(): bool
    {
        return $this->amountMinor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amountMinor === $other->amountMinor;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amountMinor >= $other->amountMinor;
    }

    /**
     * @return array{amount_minor: int, currency: string}
     */
    public function toArray(): array
    {
        return ['amount_minor' => $this->amountMinor, 'currency' => $this->currency->value];
    }

    /**
     * @return array{amount_minor: int, currency: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }
}
