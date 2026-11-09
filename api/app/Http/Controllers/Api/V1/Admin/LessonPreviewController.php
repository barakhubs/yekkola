<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Authoring\Enums\LessonType;
use App\Domain\Authoring\Enums\VersionState;
use App\Domain\Authoring\Models\CourseVersion;
use App\Domain\Authoring\Models\Lesson;
use App\Domain\Authoring\Models\MediaAsset;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Platform\Settings\ProtectionSettings;
use App\Http\Controllers\Controller;
use App\Integrations\Video\HlsPlaylist;
use App\Integrations\Video\Rendition;
use App\Integrations\Video\VideoProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * Reviewers play or open a lesson of the version they review (PRD-09 FR-07), before it is live and without
 * enrolling: `moderation.review` staff only, with their session credentials on every playlist and key request
 * (no stream limit, no viewer watermark). Documents are the professor's original PDF, never shown to students.
 */
final class LessonPreviewController extends Controller
{
    private const PLAYLIST_TYPE = 'application/vnd.apple.mpegurl';

    /**
     * What the review screen needs to preview one lesson: the staff playlist (video/audio) or the document link.
     *
     * @return array{data: array{kind: string, playlist_url: string|null, poster_url: string|null, document_url: string|null}}
     */
    public function show(CourseVersion $version, string $lesson, VideoProvider $videos, ProtectionSettings $settings): array
    {
        $found = $this->lesson($version, $lesson);

        if ($found->type === LessonType::Document) {
            abort_if($found->getFirstMedia(Lesson::DOCUMENT) === null, 404);

            return ['data' => [
                'kind' => 'document',
                'playlist_url' => null,
                'poster_url' => null,
                'document_url' => route('admin.lesson-previews.document', [$version->id, $found->id]),
            ]];
        }

        $asset = $this->readyAsset($found);

        return ['data' => [
            'kind' => $asset->kind->value,
            'playlist_url' => route('admin.media-previews.master', $asset->id),
            'poster_url' => $videos->posterUrl($asset, $settings->playback_token_ttl_minutes * 60),
            'document_url' => null,
        ]];
    }

    /** The professor's original PDF, inline. Each opening is audited. */
    public function document(CourseVersion $version, string $lesson, Request $request, RecordAudit $audit): BaseResponse
    {
        $found = $this->lesson($version, $lesson);
        $media = $found->getFirstMedia(Lesson::DOCUMENT);
        abort_if($found->type !== LessonType::Document || $media === null, 404);

        $audit->handle('lesson.document_previewed', $found, ['course_version_id' => $version->id]);
        $response = $media->toInlineResponse($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    public function master(MediaAsset $asset): Response
    {
        $this->assertReady($asset);

        return $this->playlist(HlsPlaylist::master(
            $asset->renditionList(),
            fn (Rendition $r): string => route('admin.media-previews.media', ['asset' => $asset->id, 'rendition' => $r->name]),
        ));
    }

    public function media(MediaAsset $asset, string $rendition, VideoProvider $videos, ProtectionSettings $settings): Response
    {
        $this->assertReady($asset);
        $chosen = $asset->rendition($rendition);
        abort_if($chosen === null, 404);
        $ttl = min(21_600, $settings->playback_token_ttl_minutes * 60 + 2 * (int) $asset->duration_seconds);

        return $this->playlist($videos->mediaPlaylist($asset, $chosen, route('admin.media-previews.key', $asset->id), $ttl));
    }

    public function key(MediaAsset $asset): Response
    {
        $this->assertReady($asset);

        return response((string) $asset->key(), 200, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** A lesson of a version staff can see (submitted or decided; never someone's private draft). */
    private function lesson(CourseVersion $version, string $lessonId): Lesson
    {
        abort_if($version->state === VersionState::Draft, 404);

        return $version->lessons()->findOrFail($lessonId);
    }

    private function readyAsset(Lesson $lesson): MediaAsset
    {
        $asset = $lesson->media_asset_id === null ? null : MediaAsset::query()->find($lesson->media_asset_id);
        abort_if($asset === null, 404);
        $this->assertReady($asset);

        return $asset;
    }

    private function assertReady(MediaAsset $asset): void
    {
        abort_unless($asset->isReady() && $asset->key() !== null, 409);
    }

    private function playlist(string $body): Response
    {
        return response($body, 200, ['Content-Type' => self::PLAYLIST_TYPE, 'Cache-Control' => 'no-store, private']);
    }
}
