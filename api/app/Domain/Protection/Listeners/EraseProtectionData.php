<?php

declare(strict_types=1);

namespace App\Domain\Protection\Listeners;

use App\Domain\Identity\Events\UserAnonymizing;
use App\Domain\Protection\Models\DocumentStamp;
use App\Domain\Protection\Models\StreamSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Erases a deleted account's protection data (PRD-01 FR-11): stamped documents carry the student's name and
 * masked phone, so the rows go now and the files once the deletion commits. Stream sessions hold no
 * personal data beyond the user id and are ended.
 */
final class EraseProtectionData
{
    public function handle(UserAnonymizing $event): void
    {
        $stamps = DocumentStamp::query()->where('user_id', $event->user->id);
        $paths = $stamps->clone()->whereNotNull('path')->pluck('path')->all();
        $stamps->delete();

        StreamSession::query()->where('user_id', $event->user->id)->whereNull('ended_at')
            ->update(['ended_at' => now(), 'updated_at' => now()]);

        DB::afterCommit(fn () => Storage::disk((string) config('yekkola.private_disk'))->delete($paths));
    }
}
