<?php

declare(strict_types=1);

namespace App\Domain\Professors\Models;

use App\Domain\Professors\Enums\LegalDocument;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Record that a user accepted a version of a legal text (PRD-02 FR-10). Never updated.
 *
 * @property string $id
 * @property string $user_id
 * @property LegalDocument $document
 * @property string $version
 * @property \Illuminate\Support\Carbon $accepted_at
 * @property string|null $ip
 */
final class TermsAcceptance extends Model
{
    use HasUlids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document' => LegalDocument::class,
            'accepted_at' => 'datetime',
        ];
    }

    public static function hasAcceptedCurrent(string $userId, LegalDocument $document): bool
    {
        return self::query()
            ->where('user_id', $userId)
            ->where('document', $document)
            ->where('version', $document->currentVersion())
            ->exists();
    }

    public static function record(string $userId, LegalDocument $document, ?string $ip): self
    {
        return self::query()->create([
            'user_id' => $userId,
            'document' => $document,
            'version' => $document->currentVersion(),
            'accepted_at' => now(),
            'ip' => $ip,
        ]);
    }
}
