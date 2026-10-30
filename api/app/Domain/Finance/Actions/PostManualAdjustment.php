<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\EntryLine;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Enums\LedgerTransactionType;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\ConnectionInterface;

/**
 * A manual correction of a professor's available balance by finance (PRD-08 FR-11): a balanced transaction against
 * platform revenue. The label is shown to the professor on their statement; the reason stays in the audit log.
 */
final class PostManualAdjustment
{
    public const REFERENCE = 'adjustment';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly PostLedgerTransaction $post,
        private readonly RecordAudit $recordAudit,
    ) {}

    /**
     * @param  bool  $toProfessor  True: the platform owes the professor more; false: takes it back (may go negative).
     * @param  string  $requestKey  The request's `Idempotency-Key`: the posting's reference is derived from it (with the
     *                              staff member), so a retry can never post twice, whatever happened to the cache.
     */
    public function handle(ProfessorProfile $professor, Money $amount, bool $toProfessor, string $label, string $reason, User $staff, string $requestKey): LedgerTransaction
    {
        return $this->db->transaction(function () use ($professor, $amount, $toProfessor, $label, $reason, $staff, $requestKey): LedgerTransaction {
            $lines = $toProfessor
                ? [EntryLine::debit(LedgerAccountType::PlatformRevenue, $amount), EntryLine::credit(LedgerAccountType::ProfessorAvailable, $amount, $professor->id)]
                : [EntryLine::debit(LedgerAccountType::ProfessorAvailable, $amount, $professor->id), EntryLine::credit(LedgerAccountType::PlatformRevenue, $amount)];

            $transaction = $this->post->handle(LedgerTransactionType::Adjustment, self::REFERENCE, self::referenceFor($staff, $requestKey), $lines, memo: $label, createdBy: $staff->id);
            assert($transaction !== null); // amount > 0 is validated
            if (! $transaction->wasRecentlyCreated) {
                return $transaction; // a retry: already posted and audited
            }

            $this->recordAudit->handle('ledger.adjusted', $transaction, [
                'professor_profile_id' => $professor->id,
                'amount_minor' => $amount->amountMinor,
                'currency' => $amount->currency->value,
                'to_professor' => $toProfessor,
                'label' => $label,
                'reason' => $reason,
            ], causer: $staff);

            return $transaction;
        });
    }

    /** A stable, ULID-shaped reference for this staff member's request key (Crockford base32 of a hash). */
    public static function referenceFor(User $staff, string $requestKey): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $bytes = hash('sha256', $staff->id.'|'.$requestKey, binary: true);
        $reference = $alphabet[ord($bytes[0]) % 8]; // a ULID's first character is 0–7
        for ($i = 1; $i < 26; $i++) {
            $reference .= $alphabet[ord($bytes[$i]) % 32];
        }

        return $reference;
    }
}
