<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Exceptions\CommerceException;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\NewOrder;
use App\Domain\Identity\Actions\FindOrRegisterStudent;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Settings\CommerceSettings;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Str;

/**
 * Someone pays for a student (PRD-05 FR-14, admin setting "separate payer"): a signed-in buyer, or a parent
 * with no account. The student is found or registered by phone number. A guest gets a one-time access token
 * to follow and pay the order (only its hash is stored). Orders for one student number are capped per day.
 */
final class BuyForStudent
{
    /** Orders placed for one student number by others, per day (limits probing and spam). */
    public const DAILY_ORDERS_PER_STUDENT = 10;

    public function __construct(
        private readonly CommerceSettings $settings,
        private readonly FindOrRegisterStudent $students,
        private readonly CreateOrder $create,
        private readonly RateLimiter $limiter,
    ) {}

    /**
     * @param  list<string>  $courseIds
     * @return array{order: Order, access_token: string|null} The token is given once, to a guest.
     */
    public function handle(
        ?User $buyer,
        PhoneNumber $studentPhone,
        string $idempotencyKey,
        string $locale,
        array $courseIds = [],
        ?string $bundleId = null,
        ?string $couponCode = null,
        ?PhoneNumber $payer = null,
    ): array {
        if (! $this->settings->separate_payer_enabled) {
            throw CommerceException::separatePayerDisabled();
        }

        if ($buyer !== null && $buyer->phone->equals($studentPhone)) {
            return ['order' => $this->create->handle(NewOrder::forSelf($buyer, $idempotencyKey, $courseIds, $bundleId, $couponCode)), 'access_token' => null];
        }

        $key = 'orders-for-student:'.$studentPhone->e164;
        if ($this->limiter->tooManyAttempts($key, self::DAILY_ORDERS_PER_STUDENT)) {
            throw CommerceException::beneficiaryUnavailable();
        }

        $student = $this->students->handle($studentPhone, $locale);
        if (! $student->isActive()) {
            throw CommerceException::beneficiaryUnavailable();
        }

        $token = $buyer === null ? Str::random(48) : null;
        $order = $this->create->handle(new NewOrder(
            buyer: $buyer,
            beneficiary: $student,
            idempotencyKey: $idempotencyKey,
            courseIds: $courseIds,
            bundleId: $bundleId,
            couponCode: $couponCode,
            payer: $payer,
            guestTokenHash: $token === null ? null : hash('sha256', $token),
        ));

        if (! $order->wasRecentlyCreated) {
            return ['order' => $order, 'access_token' => null]; // replay: the token was given the first time
        }

        $this->limiter->hit($key, 86_400);

        return ['order' => $order, 'access_token' => $token];
    }
}
