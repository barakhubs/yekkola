<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Actions;

use App\Domain\Authoring\Enums\MediaAssetStatus;
use App\Domain\Authoring\Enums\MediaKind;
use App\Domain\Authoring\Exceptions\AuthoringException;
use App\Domain\Authoring\Jobs\ProcessMediaAsset;
use App\Domain\Authoring\Models\Course;
use App\Domain\Authoring\Models\CourseVersion;
use App\Domain\Authoring\Models\MediaAsset;
use App\Domain\Platform\Settings\CatalogSettings;
use App\Integrations\Video\UploadIncomplete;
use App\Integrations\Video\UploadSession;
use App\Integrations\Video\VideoProvider;
use Illuminate\Database\ConnectionInterface;

/**
 * Video/audio uploads (PRD-03 FR-04/05, arch §1.3 B). The browser sends the file straight to storage in parts:
 * start → part URLs (in batches, again whenever one expires) → complete. Completing checks the assembled
 * object and queues processing; the asset moves `waiting_upload → processing → ready | errored`.
 */
final class MediaUploads
{
    /** Part URLs returned with the start, so small files need no second call. */
    public const FIRST_BATCH = 20;

    public function __construct(
        private readonly EditDraft $editDraft,
        private readonly VideoProvider $videos,
        private readonly CatalogSettings $catalog,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * A new asset for a draft lesson, replacing whatever file it had (the old asset stays with older versions).
     *
     * @return array{asset: MediaAsset, session: UploadSession, parts: list<array{number: int, url: string}>}
     */
    public function start(Course $course, string $lessonId, int $sizeBytes, string $contentType): array
    {
        $result = $this->editDraft->withDraft($course, function (CourseVersion $draft) use ($course, $lessonId, $sizeBytes, $contentType): array {
            $lesson = $draft->lessons()->findOrFail($lessonId);
            $previous = $lesson->media_asset_id;
            $kind = $lesson->type->mediaKind() ?? throw AuthoringException::lessonTypeMismatch();

            $maxMb = $this->maxMb($kind);
            if ($sizeBytes > $maxMb * 1024 * 1024) {
                throw AuthoringException::uploadTooLarge($maxMb);
            }
            // Browsers don't know every container's type (they send octet-stream); FFmpeg checks the file anyway.
            // An audio lesson may come from a video recording: only its sound is kept.
            $typeOk = $contentType === 'application/octet-stream'
                || str_starts_with($contentType, $kind->value.'/')
                || ($kind === MediaKind::Audio && str_starts_with($contentType, 'video/'));
            if (! $typeOk) {
                throw AuthoringException::lessonTypeMismatch();
            }

            $asset = MediaAsset::query()->create([
                'professor_profile_id' => $course->professor_profile_id,
                'kind' => $kind,
                'provider' => $this->videos->name(),
                'status' => MediaAssetStatus::WaitingUpload,
                'size_bytes' => $sizeBytes,
            ]);
            $session = $this->videos->startUpload($asset, $sizeBytes, $contentType);
            $asset->forceFill(['provider_upload_id' => $session->uploadId, 'source_key' => $session->sourceKey])->save();
            $lesson->forceFill(['media_asset_id' => $asset->id, 'duration_seconds' => null])->save();

            $first = range(1, min(self::FIRST_BATCH, $session->partCount));

            return ['asset' => $asset, 'session' => $session, 'parts' => self::list($this->videos->uploadPartUrls($asset, $first, $this->ttl())), 'previous' => $previous];
        });

        // A replaced file still waiting for its upload goes away (outside the draft lock: an R2 call).
        $previous = $result['previous'] === null ? null : MediaAsset::query()->find($result['previous']);
        if ($previous !== null && $previous->status === MediaAssetStatus::WaitingUpload) {
            $this->videos->abortUpload($previous);
            $previous->forceFill(['status' => MediaAssetStatus::Cancelled])->save();
        }
        unset($result['previous']);

        return $result;
    }

    /**
     * @param  list<int>  $partNumbers
     * @return list<array{number: int, url: string}>
     */
    public function partUrls(MediaAsset $asset, array $partNumbers): array
    {
        if ($asset->status !== MediaAssetStatus::WaitingUpload) {
            throw AuthoringException::uploadNotOpen();
        }

        $count = $this->partCount($asset);
        $valid = array_values(array_unique(array_filter($partNumbers, fn (int $n): bool => $n >= 1 && $n <= $count)));

        return self::list($this->videos->uploadPartUrls($asset, $valid, $this->ttl()));
    }

    /**
     * @param  array<int, string>  $urls
     * @return list<array{number: int, url: string}>
     */
    private static function list(array $urls): array
    {
        ksort($urls);

        return array_map(fn (int $number, string $url): array => ['number' => $number, 'url' => $url], array_keys($urls), array_values($urls));
    }

    /**
     * Part URLs don't cap how many bytes are sent, so the assembled object is checked against the size limit
     * again: an oversized file is deleted and the asset marked `errored`.
     *
     * @param  list<array{number: int, etag: string}>  $parts
     */
    public function complete(MediaAsset $asset, array $parts): MediaAsset
    {
        [$asset, $tooLarge] = $this->db->transaction(function () use ($asset, $parts): array {
            $locked = MediaAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== MediaAssetStatus::WaitingUpload) {
                return [$locked, false]; // a retried "complete": already done
            }

            // Every part, once: a subset would assemble a truncated file.
            $numbers = array_column($parts, 'number');
            sort($numbers);
            if ($numbers !== range(1, $this->partCount($locked))) {
                throw AuthoringException::uploadIncomplete();
            }

            try {
                $size = $this->videos->completeUpload($locked, $parts);
            } catch (UploadIncomplete) {
                throw AuthoringException::uploadIncomplete();
            }

            if ($size > $this->maxMb($locked->kind) * 1024 * 1024) {
                $locked->forceFill(['status' => MediaAssetStatus::Errored, 'size_bytes' => $size, 'error' => 'Larger than the upload limit.'])->save();

                return [$locked, true];
            }

            $locked->forceFill(['status' => MediaAssetStatus::Processing, 'size_bytes' => $size, 'error' => null])->save();
            ProcessMediaAsset::dispatch($locked->id)->afterCommit();

            return [$locked, false];
        });

        if ($tooLarge) {
            $this->videos->deleteAsset($asset);

            throw AuthoringException::uploadTooLarge($this->maxMb($asset->kind));
        }

        return $asset;
    }

    private function maxMb(MediaKind $kind): int
    {
        return $kind === MediaKind::Video ? $this->catalog->max_video_upload_mb : $this->catalog->max_audio_upload_mb;
    }

    private function partCount(MediaAsset $asset): int
    {
        $partSize = UploadSession::partSize((int) $asset->size_bytes, (int) config('yekkola.media.part_size_mb') * 1024 * 1024);

        return max(1, (int) ceil((int) $asset->size_bytes / $partSize));
    }

    private function ttl(): int
    {
        return (int) config('yekkola.media.upload_url_ttl_minutes') * 60;
    }
}
