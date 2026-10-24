<?php

declare(strict_types=1);

namespace App\Domain\Protection\Jobs;

use App\Domain\Identity\Models\User;
use App\Domain\Protection\Enums\DocumentStampStatus;
use App\Domain\Protection\Models\DocumentStamp;
use App\Domain\Protection\Stamping\DocumentNotStampable;
use App\Domain\Protection\Stamping\DocumentStamper;
use App\Domain\Protection\ViewerMark;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Makes one student's stamped copy of a document (PRD-07 FR-12) and stores it on the private disk. The source
 * is copied to a local temp file first, so this works whatever disk the original is on.
 */
final class StampDocument implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $stampId)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->stampId;
    }

    public function handle(DocumentStamper $stamper): void
    {
        $stamp = DocumentStamp::query()->find($this->stampId);
        if ($stamp === null || $stamp->status !== DocumentStampStatus::Pending) {
            return;
        }

        $source = Media::query()->find($stamp->source_media_id);
        $user = User::query()->find($stamp->user_id);
        if ($source === null || $user === null) {
            $stamp->delete();

            return;
        }

        $local = tempnam(sys_get_temp_dir(), 'yk-doc-');
        try {
            $stream = Storage::disk($source->disk)->readStream($source->getPathRelativeToRoot());
            file_put_contents((string) $local, $stream);

            $pdf = $stamper->stamp((string) $local, ViewerMark::stamp($user, CarbonImmutable::now()));
            $path = "stamped-documents/{$user->id}/{$stamp->id}.pdf";
            Storage::disk((string) config('yekkola.private_disk'))->put($path, $pdf);

            $stamp->forceFill(['status' => DocumentStampStatus::Ready, 'path' => $path, 'error' => null])->save();
        } catch (DocumentNotStampable $e) {
            // Uploads are checked, so this means a damaged file: never fall back to the unstamped copy.
            Log::warning('Document could not be stamped', ['stamp' => $stamp->id, 'media' => $source->id, 'error' => $e->getMessage()]);
            $stamp->forceFill(['status' => DocumentStampStatus::Failed, 'error' => mb_substr($e->getMessage(), 0, 250)])->save();
        } finally {
            if (is_string($local)) {
                @unlink($local);
            }
        }
    }

    public function failed(Throwable $e): void
    {
        DocumentStamp::query()->whereKey($this->stampId)->where('status', DocumentStampStatus::Pending)
            ->update(['status' => DocumentStampStatus::Failed, 'error' => mb_substr($e->getMessage(), 0, 250)]);
    }
}
