<?php

declare(strict_types=1);

use App\Domain\Authoring\Enums\MediaKind;
use App\Domain\Authoring\Models\MediaAsset;
use App\Integrations\Video\FakeVideoProvider;
use App\Integrations\Video\HlsPlaylist;
use App\Integrations\Video\Rendition;
use App\Integrations\Video\UploadIncomplete;
use App\Integrations\Video\UploadSession;

/*
| What every VideoProvider must do for the domain code. The R2 driver needs real R2 + FFmpeg: it is checked
| on staging (docs/spikes/r2-hls-media.md); its playlist signing goes through HlsPlaylist, tested here.
*/

function fakeAsset(MediaKind $kind = MediaKind::Video, int $size = 3 * 1024 * 1024): MediaAsset
{
    return new MediaAsset(['id' => (string) Str::ulid(), 'kind' => $kind, 'size_bytes' => $size, 'has_poster' => $kind === MediaKind::Video]);
}

it('splits uploads into parts R2 accepts', function () {
    $mb = 1024 * 1024;

    expect(UploadSession::partSize(25 * $mb, 10 * $mb))->toBe(10 * $mb)
        ->and(UploadSession::partSize(25 * $mb, 1 * $mb))->toBe(5 * $mb) // R2 minimum
        ->and(UploadSession::partSize(200_000 * $mb, 10 * $mb))->toBe(20 * $mb); // at most 10,000 parts
});

it('completes an upload only with every part and its ETag', function () {
    $provider = new FakeVideoProvider(cache()->store());
    $asset = fakeAsset();
    $session = $provider->startUpload($asset, (int) $asset->size_bytes, 'video/mp4');
    $asset->provider_upload_id = $session->uploadId;

    expect($session->partCount)->toBe(3)
        ->and($provider->uploadPartUrls($asset, [1, 2], 60))->toHaveKeys([1, 2]);

    $parts = array_map(fn (int $n) => ['number' => $n, 'etag' => (string) $provider->receivePart($session->uploadId, $n)], [1, 2, 3]);
    expect(fn () => $provider->completeUpload($asset, array_slice($parts, 0, 2)))->toThrow(UploadIncomplete::class)
        ->and(fn () => $provider->completeUpload($asset, [...array_slice($parts, 0, 2), ['number' => 3, 'etag' => '"wrong"']]))->toThrow(UploadIncomplete::class)
        ->and($provider->completeUpload($asset, $parts))->toBe($asset->size_bytes)
        ->and($provider->receivePart($session->uploadId, 1))->toBeNull();
});

it('reads an open upload whose part count the cache gives back as a string (Redis)', function () {
    $provider = new FakeVideoProvider(cache()->store());
    $asset = fakeAsset();
    $session = $provider->startUpload($asset, (int) $asset->size_bytes, 'video/mp4');
    $asset->provider_upload_id = $session->uploadId;
    cache()->put('fake-video:upload:'.$session->uploadId, (string) $session->partCount, now()->addDay());

    $parts = array_map(fn (int $n) => ['number' => $n, 'etag' => (string) $provider->receivePart($session->uploadId, $n)], [1, 2, 3]);
    expect($parts[0]['etag'])->toBe('"fake-1"')
        ->and($provider->completeUpload($asset, $parts))->toBe($asset->size_bytes);
});

it('processes video into a ladder and audio into one rendition, each with its own key', function () {
    $provider = new FakeVideoProvider(cache()->store());

    $video = $provider->process(fakeAsset(), 'Yekkola · Marie');
    $audio = $provider->process(fakeAsset(MediaKind::Audio), 'Yekkola · Marie');

    expect(array_map(fn (Rendition $r) => $r->name, $video->renditions))->toBe(['720p', '480p', '360p'])
        ->and($video->hasPoster)->toBeTrue()
        ->and($audio->renditions)->toHaveCount(1)
        ->and($audio->hasPoster)->toBeFalse()
        ->and(strlen($video->key))->toBe(16)
        ->and($video->key)->not->toBe($audio->key);
});

it('signs every segment and points the key at our endpoint, keeping the tags', function () {
    $raw = "#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXT-X-KEY:METHOD=AES-128,URI=\"yekkola-key\"\n#EXTINF:6.0,\nseg_00000.ts\n#EXTINF:4.2,\nseg_00001.ts\n#EXT-X-ENDLIST\n";

    $signed = HlsPlaylist::withKey(
        HlsPlaylist::signSegments($raw, fn (string $segment) => "https://r2.test/{$segment}?sig=abc"),
        'https://api.test/api/v1/lessons/L1/hls/key?session=S1',
    );

    expect($signed)->toContain("https://r2.test/seg_00000.ts?sig=abc\n")
        ->and($signed)->toContain("https://r2.test/seg_00001.ts?sig=abc\n")
        ->and($signed)->toContain('#EXT-X-KEY:METHOD=AES-128,URI="https://api.test/api/v1/lessons/L1/hls/key?session=S1"')
        ->and($signed)->toContain("#EXTINF:4.2,\n")
        ->and($signed)->toEndWith("#EXT-X-ENDLIST\n")
        ->and($signed)->not->toContain('yekkola-key');
});

it('lists renditions in the master playlist with their bandwidth and size', function () {
    $master = HlsPlaylist::master(
        [new Rendition('720p', 2_000_000, 1, 1280, 720), new Rendition('audio', 70_000, 1)],
        fn (Rendition $r) => "https://api.test/{$r->name}.m3u8",
    );

    expect($master)->toStartWith('#EXTM3U')
        ->and($master)->toContain("#EXT-X-STREAM-INF:BANDWIDTH=2000000,RESOLUTION=1280x720,NAME=\"720p\"\nhttps://api.test/720p.m3u8")
        ->and($master)->toContain("#EXT-X-STREAM-INF:BANDWIDTH=70000,NAME=\"audio\"\nhttps://api.test/audio.m3u8");
});

it('processes at once with the fake driver, and on the video queue with R2', function () {
    config(['yekkola.drivers.video' => 'fake', 'yekkola.media.queue_connection' => 'redis-media']);
    expect((new App\Domain\Authoring\Jobs\ProcessMediaAsset('a'))->connection)->toBe('sync');

    config(['yekkola.drivers.video' => 'r2']);
    expect((new App\Domain\Authoring\Jobs\ProcessMediaAsset('a'))->connection)->toBe('redis-media');
});

it('stores R2 multipart upload ids, which are longer than 255 characters', function () {
    $professor = actingAsProfessor();
    $asset = MediaAsset::query()->create([
        'professor_profile_id' => $professor->id, 'kind' => 'video', 'provider' => 'r2', 'status' => 'waiting_upload',
        'provider_upload_id' => str_repeat('A1b_-', 80), 'source_key' => 'media/x/source',
    ]);

    expect(strlen((string) $asset->fresh()->provider_upload_id))->toBe(400);
});

it('sends transcoding to the long-timeout media connection of whichever queue driver is in use', function () {
    foreach (['redis' => 'redis-media', 'database' => 'database-media'] as $driver => $connection) {
        expect(config("queue.connections.{$connection}.retry_after"))->toBeGreaterThan(config('yekkola.media.timeout_seconds') + 1_800)
            ->and(config("queue.connections.{$connection}.driver"))->toBe($driver);
    }
});
