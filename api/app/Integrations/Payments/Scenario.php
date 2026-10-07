<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

/**
 * Outcomes the FakeGateway can simulate (PRD-05 FR-18).
 */
enum Scenario: string
{
    case Succeed = 'succeed';
    case Fail = 'fail';
    case PendingForever = 'pending_forever';
    case LateSuccess = 'late_success';
    case Reversal = 'reversal';

    public static function fromMsisdn(string $e164): self
    {
        return match (substr($e164, -4)) {
            '0001' => self::Fail,
            '0002' => self::PendingForever,
            '0003' => self::LateSuccess,
            '0004' => self::Reversal,
            default => self::Succeed,
        };
    }
}
