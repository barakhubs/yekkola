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
use Illuminate\Database\ConnectionInterface;

/**
 * Someone pays for a student (PRD-05 FR-14, admin setting "separate payer"): a signed-in buyer, or a parent
 * with no account. The student is found or registered by phone number — registered only if the order is
 * actually created. Nothing about the student shows in the outcome (see QuoteOrder `private`). Attempts are
 * capped per student number and per buyer (config yekkola.checkout).
 *
 * A guest's access token is derived from the order's student and the client's idempotency key, so a retry
 * from anywhere gets the same token back; only its hash is stored.
 */
final class BuyForStudent
{
    public function __construct(
        private readonly CommerceSettings $settings,
        private readonly FindOrRegisterStudent $students,
        private readonly CreateOrder $create,
        private readonly RateLimiter $limiter,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * @param  list<string>  $courseIds
     * @return array{order: Order, access_token: string|null} The token is for a guest.
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
            return ['order' => $this->create->handle(NewOrder::forSelf($buyer, $idempotencyKey, $courseIds, $bundleId, $couponCode, $payer)), 'access_token' => null];
        }

        $this->throttle($buyer, $studentPhone);

        $order = $this->db->transaction(function () use ($buyer, $studentPhone, $idempotencyKey, $locale, $courseIds, $bundleId, $couponCode, $payer): Order {
            $student = $this->students->handle($studentPhone, $locale);
            if (! $student->isActive() || $student->deletion_requested_at !== null) {
                throw CommerceException::beneficiaryUnavailable();
            }

            return $this->create->handle(new NewOrder(
                buyer: $buyer,
                beneficiary: $student,
                idempotencyKey: $idempotencyKey,
                courseIds: $courseIds,
                bundleId: $bundleId,
                couponCode: $couponCode,
                payer: $payer,
                guestTokenHash: $buyer === null ? hash('sha256', self::guestToken($student->id, $idempotencyKey)) : null,
            ));
        });

        return ['order' => $order, 'access_token' => $buyer === null ? self::guestToken($order->beneficiary_id, $idempotencyKey) : null];
    }

    /** 48 hex characters (192 bits), reproducible only with the app key and the client's idempotency key. */
    public static function guestToken(string $studentId, string $idempotencyKey): string
    {
        return substr(hash_hmac('sha256', 'guest-order|'.$studentId.'|'.$idempotencyKey, (string) config('app.key')), 0, 48);
    }

    /** Every attempt counts, successful or not, so failures can't be used to probe numbers for free. */
    private function throttle(?User $buyer, PhoneNumber $studentPhone): void
    {
        $perNumber = 'orders-for-student:'.$studentPhone->e164;
        $perBuyer = 'orders-for-others:'.$buyer?->id;

        if ($this->limiter->tooManyAttempts($perNumber, (int) config('yekkola.checkout.orders_per_student_number_per_day'))
            || ($buyer !== null && $this->limiter->tooManyAttempts($perBuyer, (int) config('yekkola.checkout.students_per_buyer_per_day')))) {
            throw CommerceException::beneficiaryUnavailable();
        }

        $this->limiter->hit($perNumber, 86_400);
        if ($buyer !== null) {
            $this->limiter->hit($perBuyer, 86_400);
        }
    }
}
