<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\EntryLine;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Enums\LedgerTransactionType;
use App\Domain\Finance\Enums\PayoutBatchStatus;
use App\Domain\Finance\Enums\PayoutStatus;
use App\Domain\Finance\Models\Payout;
use App\Domain\Finance\Models\PayoutBatch;
use App\Domain\Finance\ProfessorBalances;
use App\Domain\Platform\Settings\PayoutSettings;
use App\Domain\Professors\Enums\ProfessorStatus;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use App\Integrations\Payments\DisbursementRequest;
use App\Integrations\Payments\Exceptions\GatewayUnavailable;
use App\Integrations\Payments\Exceptions\UnknownTransaction;
use App\Integrations\Payments\GatewayResult;
use App\Integrations\Payments\GatewayStatus;
use App\Integrations\Payments\PaymentGateway;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends one approved payout and follows it to the end (PRD-08 FR-08): `PaymentGateway::disburse` under the
 * payout's own reference, then webhooks and polls — the gateway's status API is the only source of truth.
 *
 * Ledger: only a confirmed payout is posted (`payout`: professor available → gateway clearing, plus the fee).
 * Until then the money simply stays available, so a failed payout needs no posting and is paid in the next run.
 * A payout the operator reverses after success is mirrored (`payout_failed`) and the money is available again.
 */
final class PayoutLifecycle
{
    public const REFERENCE = 'payout';

    /** A payout the gateway never registered is re-sent under the same reference for this long, then failed. */
    public const GIVE_UP_HOURS = 24;

    /**
     * After a payout fails, its professor isn't paid again for this long: a gateway can still report the failed
     * transfer as a late success, and paying the same balance twice must not follow from it.
     */
    public const UNCERTAIN_HOURS = 48;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly PaymentGateway $gateway,
        private readonly PostLedgerTransaction $post,
        private readonly ReversePosting $reverse,
        private readonly PayoutSettings $settings,
        private readonly LoggerInterface $log,
    ) {}

    public function execute(Payout $payout): void
    {
        $send = $this->db->transaction(function () use ($payout): ?Payout {
            $payout = Payout::query()->whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if ($payout->status !== PayoutStatus::Pending) {
                return null; // already sent, or left out
            }

            // Checked again at sending: things may have changed since the batch was built.
            $refusal = $this->refusal($payout);
            if ($refusal !== null) {
                $payout->forceFill(['status' => PayoutStatus::Failed, 'failure_code' => $refusal])->save();

                return null;
            }

            $payout->forceFill(['status' => PayoutStatus::Processing, 'gateway' => $this->gateway->name(), 'sent_at' => now()])->save();

            return $payout;
        });

        if ($send !== null) {
            $this->send($send);
        }
        $this->settleBatch($payout->batch()->firstOrFail());
    }

    /**
     * A webhook or poll about one payout, by its gateway reference.
     *
     * @return bool Whether the gateway answered (false: try again later).
     */
    public function syncReference(string $reference): bool
    {
        $payout = Payout::query()->where('reference', $reference)->first();

        return $payout === null || $this->sync($payout);
    }

    /** @return bool Whether the gateway answered (false: try again later). */
    public function sync(Payout $payout): bool
    {
        try {
            $this->apply($payout, $this->gateway->disbursementStatus($payout->reference));
        } catch (UnknownTransaction) {
            if ($payout->status === PayoutStatus::Processing) {
                $this->unknownAtGateway($payout);
            }
        } catch (GatewayUnavailable) {
            $this->touch($payout);

            return false;
        }

        return true;
    }

    /** Batch outcome once no line is waiting any more: completed, or partially failed. */
    public function settleBatch(PayoutBatch $batch): void
    {
        $this->db->transaction(function () use ($batch): void {
            $batch = PayoutBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($batch->status === PayoutBatchStatus::Draft) {
                return;
            }
            $lines = $batch->payouts()->pluck('status')->map(fn ($s) => $s instanceof PayoutStatus ? $s : PayoutStatus::from((string) $s));
            if ($lines->contains(fn (PayoutStatus $s) => in_array($s, [PayoutStatus::Pending, PayoutStatus::Processing], true))) {
                return;
            }

            $status = $lines->contains(PayoutStatus::Failed) ? PayoutBatchStatus::PartiallyFailed : PayoutBatchStatus::Completed;
            if ($batch->status !== $status) {
                $batch->forceFill(['status' => $status])->save();
            }
        });
    }

    /** Why this payout mustn't be sent now, or null. */
    private function refusal(Payout $payout): ?string
    {
        $professor = ProfessorProfile::query()->find($payout->professor_profile_id);

        return match (true) {
            $professor === null || $professor->status !== ProfessorStatus::Active => 'professor_unavailable',
            $professor->payout_hold_until !== null && $professor->payout_hold_until->isFuture() => 'payout_on_hold',
            $professor->payout_rail !== $payout->rail || $professor->payout_msisdn !== $payout->msisdn => 'payout_method_changed',
            self::recentlyFailed($payout->professor_profile_id, $payout->currency, except: $payout->id) => 'previous_payout_uncertain',
            ProfessorBalances::availableFor($payout->professor_profile_id, Currency::from($payout->currency)) < $payout->amount_minor => 'balance_changed',
            default => null,
        };
    }

    private function send(Payout $payout, bool $resend = false): void
    {
        try {
            $this->apply($payout, $this->gateway->disburse($this->request($payout)));
        } catch (GatewayUnavailable) {
            if ($resend) {
                $this->touch($payout); // the first send may still surface: keep looking

                return;
            }
            $this->apply($payout, new GatewayResult($payout->reference, '', GatewayStatus::Failed, failureCode: 'gateway_unavailable'));
        } catch (Throwable $e) {
            // Unknown outcome (timeout, driver error): stays in flight; sync looks it up and re-sends safely.
            $this->log->warning('Payout outcome unknown', ['payout_id' => $payout->id, 'exception' => $e::class]);
            $this->touch($payout);
        }
    }

    private function unknownAtGateway(Payout $payout): void
    {
        if ($payout->sent_at !== null && $payout->sent_at->lt(now()->subHours(self::GIVE_UP_HOURS))) {
            $this->apply($payout, new GatewayResult($payout->reference, '', GatewayStatus::Failed, failureCode: 'not_found_at_gateway'));

            return;
        }
        $this->send($payout, resend: true);
    }

    private function apply(Payout $payout, GatewayResult $result): void
    {
        $this->db->transaction(function () use ($payout, $result): void {
            $payout = Payout::query()->whereKey($payout->id)->lockForUpdate()->firstOrFail();
            $payout->forceFill(['checks' => $payout->checks + 1, 'last_checked_at' => now()]);

            if ($result->reference !== $payout->reference) {
                $this->log->warning('Payout result for another reference ignored', ['payout_id' => $payout->id, 'reference' => $result->reference]);
                $payout->save();

                return;
            }
            $payout->forceFill([
                'gateway_reference' => $payout->gateway_reference ?? ($result->gatewayReference !== '' ? $result->gatewayReference : null),
                'operator_reference' => $payout->operator_reference ?? $result->operatorReference,
            ]);

            match (true) {
                // A failed payout may still turn out to have gone through: the money left, so it is paid.
                // (Never after a reversal: that transfer is over.)
                $result->status === GatewayStatus::Succeeded && ($payout->status === PayoutStatus::Processing
                    || ($payout->status === PayoutStatus::Failed && $payout->failure_code !== 'reversed')) => $this->paid($payout, $result),
                $result->status === GatewayStatus::Reversed && $payout->status === PayoutStatus::Paid => $this->reversed($payout),
                in_array($result->status, [GatewayStatus::Failed, GatewayStatus::Reversed], true) && $payout->status === PayoutStatus::Processing => $payout->forceFill([
                    'status' => PayoutStatus::Failed,
                    'failure_code' => $result->failureCode ?? 'failed',
                    'failure_message' => $result->failureMessage,
                ]),
                default => null, // pending, or nothing new
            };

            $payout->save();
        });

        $this->settleBatch($payout->batch()->firstOrFail());
    }

    private function paid(Payout $payout, GatewayResult $result): void
    {
        $requested = $payout->amount();
        $confirmed = $result->amount !== null && $result->amount->currency === $requested->currency ? $result->amount : $requested;
        if ($result->amount !== null && ! $result->amount->equals($requested)) {
            // Posted as confirmed when in our currency: the professor's available balance shows the difference.
            $this->log->critical('Payout confirmed with a different amount', ['payout_id' => $payout->id]);
        }
        $fee = $result->fee !== null && $result->fee->currency === $requested->currency ? $result->fee : null;
        if ($payout->status === PayoutStatus::Failed) {
            // Posted (the money left), but staff should look: the professor may have been paid again since.
            $this->log->critical('Payout succeeded after it was reported failed', ['payout_id' => $payout->id]);
        }

        $payout->forceFill([
            'status' => PayoutStatus::Paid,
            'paid_at' => $result->completedAt ?? now(),
            'fee_minor' => $fee?->amountMinor,
            'failure_code' => null,
            'failure_message' => null,
        ]);
        $this->post->handle(LedgerTransactionType::Payout, self::REFERENCE, $payout->id, $this->lines($payout, $confirmed, $fee), memo: "Payout {$payout->reference}");
    }

    private function reversed(Payout $payout): void
    {
        $payout->forceFill(['status' => PayoutStatus::Failed, 'failure_code' => 'reversed', 'paid_at' => null]);
        $this->reverse->handle(LedgerTransactionType::Payout, LedgerTransactionType::PayoutFailed, self::REFERENCE, $payout->id, "Payout {$payout->reference} reversed");
    }

    /**
     * @return list<EntryLine>
     */
    private function lines(Payout $payout, Money $confirmed, ?Money $fee): array
    {
        $lines = [
            EntryLine::debit(LedgerAccountType::ProfessorAvailable, $confirmed, $payout->professor_profile_id),
            EntryLine::credit(LedgerAccountType::GatewayClearing, $confirmed),
        ];

        if ($fee !== null && $fee->isPositive()) {
            // Who bears the disbursement fee is a platform setting (provisional: the platform).
            $lines[] = $this->settings->platform_pays_disbursement_fees
                ? EntryLine::debit(LedgerAccountType::GatewayFees, $fee)
                : EntryLine::debit(LedgerAccountType::ProfessorAvailable, $fee, $payout->professor_profile_id);
            $lines[] = EntryLine::credit(LedgerAccountType::GatewayClearing, $fee);
        }

        return $lines;
    }

    /** Whether a payout to this professor failed recently enough that it may still turn out to have gone through. */
    public static function recentlyFailed(string $professorProfileId, string $currency, ?string $except = null): bool
    {
        return Payout::query()
            ->where('professor_profile_id', $professorProfileId)
            ->where('currency', $currency)
            ->where('status', PayoutStatus::Failed)
            ->where('failure_code', '!=', 'reversed')
            ->whereNotNull('sent_at')
            ->where('sent_at', '>', now()->subHours(self::UNCERTAIN_HOURS))
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))
            ->exists();
    }

    private function request(Payout $payout): DisbursementRequest
    {
        return new DisbursementRequest(
            reference: $payout->reference,
            amount: $payout->amount(),
            rail: $payout->rail,
            recipient: PhoneNumber::fromString($payout->msisdn),
            recipientName: $payout->name,
            description: "Yekkola payout {$payout->reference}",
        );
    }

    private function touch(Payout $payout): void
    {
        Payout::query()->whereKey($payout->id)->update(['checks' => $payout->checks + 1, 'last_checked_at' => now()]);
    }
}
