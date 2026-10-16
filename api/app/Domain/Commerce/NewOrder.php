<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\ValueObjects\PhoneNumber;

/**
 * What to buy, and for whom (PRD-05 FR-03, FR-14). The beneficiary gets access; the buyer is the signed-in
 * account placing the order (null for a parent checking out without an account, who holds a one-time token
 * instead); the payer number, if known, is used for the payment prompt and the receipt.
 */
final readonly class NewOrder
{
    /**
     * @param  list<string>  $courseIds
     */
    public function __construct(
        public ?User $buyer,
        public User $beneficiary,
        public string $idempotencyKey,
        public array $courseIds = [],
        public ?string $bundleId = null,
        public ?string $couponCode = null,
        public ?PhoneNumber $payer = null,
        public ?string $guestTokenHash = null,
    ) {}

    /**
     * @param  list<string>  $courseIds
     */
    public static function forSelf(User $buyer, string $idempotencyKey, array $courseIds = [], ?string $bundleId = null, ?string $couponCode = null): self
    {
        return new self($buyer, $buyer, $idempotencyKey, $courseIds, $bundleId, $couponCode);
    }
}
