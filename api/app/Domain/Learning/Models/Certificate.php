<?php

declare(strict_types=1);

namespace App\Domain\Learning\Models;

use App\Domain\Authoring\Models\Course;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proof of completion (PRD-06 FR-10, FR-11). Names and title are snapshotted at issue so later edits never
 * change it; revoking keeps the row so the verification page can say "revoked". The PDF is rendered on
 * request from the snapshot, in the student's language.
 *
 * @property string $id
 * @property string $serial
 * @property string $user_id
 * @property string $course_id
 * @property string $enrollment_id
 * @property string $course_version_id
 * @property string $student_name_snapshot
 * @property string $course_title_snapshot
 * @property string $professor_name_snapshot
 * @property \Illuminate\Support\Carbon $issued_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property string|null $revoked_reason
 */
final class Certificate extends Model
{
    use HasUlids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class)->withTrashed();
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeValid(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** Public verification page on the web app; the PDF's QR code points here. */
    public function verificationUrl(): string
    {
        return rtrim((string) config('yekkola.web_url'), '/').'/certificates/'.$this->serial;
    }

    /** Random, unguessable, easy to read aloud or type: YK-XXXX-XXXX-XXXX (Crockford base32). */
    public static function newSerial(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $chars = '';
        foreach (str_split(random_bytes(12)) as $byte) {
            $chars .= $alphabet[ord($byte) % 32];
        }

        return 'YK-'.implode('-', str_split($chars, 4));
    }
}
