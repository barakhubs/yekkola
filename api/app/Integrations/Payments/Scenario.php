<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

/**
 * Outcomes the FakeGateway can simulate (PRD-05 FR-18), chosen by the last 4 digits of the
 * payer/recipient number so testers can trigger them from the real UI.
 */
enum Scenario: string
{
    /** Pending, then succeeded on the next status check. Any number not listed below. */
    case Succeed = 'succeed';
    /** …0001 — failed: insufficient funds. */
    case Fail = 'fail';
    /** …0002 — stays pending forever (order times out). */
    case PendingForever = 'pending_forever';
    /** …0003 — pending on the first check, succeeded on the second ("late success"). */
    case LateSuccess = 'late_success';
    /** …0004 — succeeded on the first check, reversed on the second. */
    case Reversal = 'reversal';
    /** …0005 — failed on the first check, succeeded on the second (PRD-08 §7). */
    case FailThenSucceed = 'fail_then_succeed';
    /** …0006 — start throws GatewayUnavailable; nothing is recorded. */
    case Unavailable = 'unavailable';
    /** …0007 — start throws GatewayOutcomeUnknown, but the transaction exists and succeeds on check. */
    case OutcomeUnknown = 'outcome_unknown';
    /** …0008 — succeeds, but the gateway confirms one collection step less than requested. */
    case AmountMismatch = 'amount_mismatch';
    /** …0009 — failed: the payer rejected the prompt. */
    case RejectedByPayer = 'rejected_by_payer';
    /** …0010 — failed: invalid recipient account (disbursements). */
    case InvalidRecipient = 'invalid_recipient';

    public static function fromMsisdn(string $e164): self
    {
        return match (substr($e164, -4)) {
            '0001' => self::Fail,
            '0002' => self::PendingForever,
            '0003' => self::LateSuccess,
            '0004' => self::Reversal,
            '0005' => self::FailThenSucceed,
            '0006' => self::Unavailable,
            '0007' => self::OutcomeUnknown,
            '0008' => self::AmountMismatch,
            '0009' => self::RejectedByPayer,
            '0010' => self::InvalidRecipient,
            default => self::Succeed,
        };
    }

    /**
     * Status reported at the given status check (1 = first check after the start).
     */
    public function statusAt(int $check): GatewayStatus
    {
        return match ($this) {
            self::Fail, self::RejectedByPayer, self::InvalidRecipient => GatewayStatus::Failed,
            self::PendingForever => GatewayStatus::Pending,
            self::Succeed, self::OutcomeUnknown, self::AmountMismatch, self::Unavailable => GatewayStatus::Succeeded,
            self::LateSuccess => $check >= 2 ? GatewayStatus::Succeeded : GatewayStatus::Pending,
            self::Reversal => $check >= 2 ? GatewayStatus::Reversed : GatewayStatus::Succeeded,
            self::FailThenSucceed => $check >= 2 ? GatewayStatus::Succeeded : GatewayStatus::Failed,
        };
    }

    /** Status returned by the start call itself. */
    public function startStatus(): GatewayStatus
    {
        return match ($this) {
            self::Fail, self::RejectedByPayer, self::InvalidRecipient => GatewayStatus::Failed,
            default => GatewayStatus::Pending,
        };
    }

    public function failureCode(): ?string
    {
        return match ($this) {
            self::Fail, self::FailThenSucceed => 'insufficient_funds',
            self::RejectedByPayer => 'rejected_by_payer',
            self::InvalidRecipient => 'invalid_recipient',
            default => null,
        };
    }
}
