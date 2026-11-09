<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Jobs;

use App\Domain\Authoring\Enums\MediaAssetStatus;
use App\Domain\Authoring\Enums\VersionState;
use App\Domain\Authoring\Models\Lesson;
use App\Domain\Authoring\Models\MediaAsset;
use App\Integrations\Video\MediaProcessingFailed;
use App\Integrations\Video\Rendition;
use App\Integrations\Video\VideoProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Transcodes one uploaded file into encrypted HLS (arch §1.3 C) on the `media` queue. A file FFmpeg can't read
 * fails at once (`errored`, the professor uploads again); infrastructure failures (R2, worker) are retried.
 * Each run writes to its own output folder, so a duplicate run can never mix its segments with another run's key:
 * whichever run commits first wins, the other's output is left unreferenced (cleanup: TODO 1.5).
 * Ready assets push their duration onto the open versions' lessons using them.
 */
final class ProcessMediaAsset implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    public int $timeout;

    public function __construct(public readonly string $mediaAssetId)
    {
        $this->onQueue('media');
        $connection = config('yekkola.media.queue_connection');
        if (is_string($connection) && $connection !== '') {
            $this->onConnection($connection);
        }
        // FFmpeg's whole budget + downloading the source and uploading the output.
        $this->timeout = (int) config('yekkola.media.timeout_seconds') + 1_800;
    }

    public function uniqueId(): string
    {
        return $this->mediaAssetId;
    }

    public function handle(VideoProvider $videos): void
    {
        $asset = MediaAsset::query()->with('professor')->find($this->mediaAssetId);
        if ($asset === null || $asset->status !== MediaAssetStatus::Processing) {
            return;
        }

        try {
            $processed = $videos->process($asset, 'Yekkola · '.$asset->professor->display_name);
        } catch (MediaProcessingFailed $e) {
            $this->markErrored($e->getMessage());

            return;
        }

        DB::transaction(function () use ($processed): void {
            $asset = MediaAsset::query()->whereKey($this->mediaAssetId)->lockForUpdate()->first();
            if ($asset === null || $asset->status !== MediaAssetStatus::Processing) {
                return;
            }

            $asset->forceFill([
                'status' => MediaAssetStatus::Ready,
                'output_prefix' => $processed->outputPrefix,
                'renditions' => array_map(fn (Rendition $r): array => $r->toArray(), $processed->renditions),
                'encryption_key' => base64_encode($processed->key),
                'has_poster' => $processed->hasPoster,
                'duration_seconds' => $processed->durationSeconds,
                'error' => null,
            ])->save();

            // Approved versions are immutable: only open versions pick up the duration (later copies inherit it).
            Lesson::query()
                ->where('media_asset_id', $asset->id)
                ->whereHas('version', fn ($q) => $q->whereIn('state', [VersionState::Draft, VersionState::Rejected, VersionState::InReview]))
                ->update(['duration_seconds' => $processed->durationSeconds]);
        });
    }

    public function failed(Throwable $e): void
    {
        $this->markErrored($e->getMessage());
    }

    private function markErrored(string $reason): void
    {
        Log::warning('Media processing failed', ['media_asset_id' => $this->mediaAssetId, 'reason' => $reason]);
        MediaAsset::query()->whereKey($this->mediaAssetId)->where('status', MediaAssetStatus::Processing)
            ->update(['status' => MediaAssetStatus::Errored, 'error' => mb_substr($reason, 0, 2000), 'updated_at' => now()]);
    }
}
