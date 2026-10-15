<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Actions;

use App\Domain\Authoring\Enums\MediaAssetStatus;
use App\Domain\Authoring\Enums\VersionState;
use App\Domain\Authoring\Models\Lesson;
use App\Domain\Authoring\Models\MediaAsset;
use App\Integrations\Video\VideoAsset;
use App\Integrations\Video\VideoAssetNotFound;
use App\Integrations\Video\VideoAssetStatus;
use App\Integrations\Video\VideoEventType;
use App\Integrations\Video\VideoProvider;
use App\Integrations\Video\VideoWebhookEvent;
use Illuminate\Database\ConnectionInterface;

/**
 * Applies a provider event to our MediaAsset. The event only says *something changed*: the asset's real
 * state is always re-fetched from the provider (never trusted from the webhook body). Ready assets push
 * their duration onto the lessons using them.
 */
final class SyncMediaAsset
{
    public function __construct(
        private readonly VideoProvider $videos,
        private readonly ConnectionInterface $db,
    ) {}

    public function fromEvent(VideoWebhookEvent $event): ?MediaAsset
    {
        $asset = $this->find($event);
        if ($asset === null) {
            return null;
        }

        return $this->db->transaction(function () use ($asset, $event): MediaAsset {
            return $this->apply(MediaAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail(), $event);
        });
    }

    public function refresh(MediaAsset $asset): MediaAsset
    {
        if ($asset->provider_asset_id === null) {
            return $asset;
        }

        try {
            $remote = $this->videos->getAsset($asset->provider_asset_id);
        } catch (VideoAssetNotFound) {
            return $asset; // not visible yet — the next event or poll will catch up
        }

        return $this->db->transaction(function () use ($asset, $remote): MediaAsset {
            return $this->store(MediaAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail(), $remote);
        });
    }

    private function apply(MediaAsset $asset, VideoWebhookEvent $event): MediaAsset
    {
        if ($event->type === VideoEventType::UploadCancelled) {
            $asset->forceFill(['status' => MediaAssetStatus::Cancelled])->save();

            return $asset;
        }

        if ($event->assetId !== null && $asset->provider_asset_id === null) {
            $asset->forceFill(['provider_asset_id' => $event->assetId, 'status' => MediaAssetStatus::Processing])->save();
        }

        if ($asset->provider_asset_id === null || ! in_array($event->type, [VideoEventType::AssetReady, VideoEventType::AssetErrored, VideoEventType::AssetCreated], true)) {
            return $asset;
        }

        try {
            return $this->store($asset, $this->videos->getAsset($asset->provider_asset_id));
        } catch (VideoAssetNotFound) {
            return $asset; // not visible yet — the next event or poll will catch up
        }
    }

    private function store(MediaAsset $asset, VideoAsset $remote): MediaAsset
    {
        if ($asset->status === MediaAssetStatus::Ready && $remote->status === VideoAssetStatus::Preparing) {
            return $asset; // a stale read racing a newer event — never move back from ready
        }

        $status = match ($remote->status) {
            VideoAssetStatus::Ready => MediaAssetStatus::Ready,
            VideoAssetStatus::Errored => MediaAssetStatus::Errored,
            VideoAssetStatus::Preparing => MediaAssetStatus::Processing,
        };

        $duration = $remote->durationSeconds === null ? null : (int) round($remote->durationSeconds);

        $asset->forceFill([
            'status' => $status,
            'playback_id' => $remote->playbackId,
            'duration_seconds' => $duration,
            'error' => $remote->errorMessage,
        ])->save();

        if ($status === MediaAssetStatus::Ready) {
            // Approved versions are immutable: only open versions pick up the duration (later copies inherit it).
            Lesson::query()
                ->where('media_asset_id', $asset->id)
                ->whereHas('version', fn ($q) => $q->whereIn('state', [VersionState::Draft, VersionState::Rejected, VersionState::InReview]))
                ->update(['duration_seconds' => $duration]);
        }

        return $asset;
    }

    private function find(VideoWebhookEvent $event): ?MediaAsset
    {
        return MediaAsset::query()
            ->when($event->passthrough !== null, fn ($q) => $q->orWhere('id', $event->passthrough))
            ->when($event->uploadId !== null, fn ($q) => $q->orWhere('provider_upload_id', $event->uploadId))
            ->when($event->assetId !== null, fn ($q) => $q->orWhere('provider_asset_id', $event->assetId))
            ->when($event->passthrough === null && $event->uploadId === null && $event->assetId === null, fn ($q) => $q->whereRaw('1 = 0'))
            ->first();
    }
}
