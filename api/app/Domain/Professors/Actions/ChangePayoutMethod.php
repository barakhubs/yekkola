<?php

declare(strict_types=1);

namespace App\Domain\Professors\Actions;

use App\Domain\Commerce\Enums\MobileMoneyRail;
use App\Domain\Identity\Actions\VerifyOtp;
use App\Domain\Identity\Enums\OtpPurpose;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Platform\Settings\PayoutSettings;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use Illuminate\Database\ConnectionInterface;

/**
 * Sets or changes where a professor's payouts go (PRD-02 FR-09). Confirmed with an OTP sent to the
 * account's own number. Changing an existing method holds payouts for a while (setting, default 48 h),
 * so a hijacked session can't redirect earnings straight away.
 */
final class ChangePayoutMethod
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly VerifyOtp $verifyOtp,
        private readonly PayoutSettings $settings,
        private readonly RecordAudit $recordAudit,
    ) {}

    public function handle(ProfessorProfile $profile, MobileMoneyRail $rail, PhoneNumber $msisdn, string $name, string $code): ProfessorProfile
    {
        $user = $profile->user;
        $challenge = $this->verifyOtp->handle($user->phone, $code, OtpPurpose::PayoutMethod, $user->id);

        return $this->db->transaction(function () use ($profile, $rail, $msisdn, $name, $challenge, $user): ProfessorProfile {
            $this->verifyOtp->consume($challenge);

            $isChange = $profile->hasPayoutMethod();
            $before = $isChange ? ['rail' => $profile->payout_rail?->value, 'msisdn' => PhoneNumber::fromString((string) $profile->payout_msisdn)->masked()] : null;

            $profile->forceFill([
                'payout_rail' => $rail,
                'payout_msisdn' => $msisdn->e164,
                'payout_name' => $name,
                'payout_method_updated_at' => now(),
                'payout_hold_until' => $isChange ? now()->addHours($this->settings->payout_method_change_hold_hours) : null,
            ])->save();

            $this->recordAudit->handle('professor.payout_method_changed', $profile, [
                'before' => $before,
                'after' => ['rail' => $rail->value, 'msisdn' => $msisdn->masked()],
            ], causer: $user);

            return $profile;
        });
    }
}
