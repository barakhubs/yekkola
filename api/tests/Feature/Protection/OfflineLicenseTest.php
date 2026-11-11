<?php

declare(strict_types=1);

use App\Domain\Authoring\Models\Lesson;
use App\Domain\Authoring\Models\MediaAsset;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Device;
use App\Domain\Platform\Settings\ProtectionSettings;
use App\Domain\Protection\Models\OfflineLicense;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->course = publishedCourse(actingAsProfessor(), 'Algèbre pour l’Exetat', ['is_free' => true, 'price_minor' => null, 'currency' => null]);
    $lessons = Lesson::query()->where('course_version_id', $this->course->live_version_id)->orderBy('position')->get();
    [$this->document, , $this->video] = [$lessons[0], $lessons[1], $lessons[2]];
    freshAuth();

    $this->token = signInMobile('+243812345678', 'install-android-0001')->json('data.token');
    $this->headers = [...bearer($this->token), 'X-Device-Id' => 'install-android-0001'];
    $this->postJson("/api/v1/courses/{$this->course->id}/enroll", [], $this->headers)->assertOk();
    $this->device = Device::query()->where('install_id', 'install-android-0001')->sole();
});

function offlineLicense(string $uid, array $headers, array $body = []): Illuminate\Testing\TestResponse
{
    freshAuth();

    return test()->postJson("/api/v1/lessons/{$uid}/offline-license", $body, $headers);
}

it('gives the registered device our lowest rendition, the key and an expiry from the setting (PRD-07 FR-07, FR-08)', function () {
    $this->freezeTime();
    $asset = MediaAsset::query()->findOrFail($this->video->media_asset_id);

    $response = offlineLicense($this->video->uid, $this->headers)->assertOk()
        ->assertJsonPath('data.lesson_uid', $this->video->uid)
        ->assertJsonPath('data.media_id', $asset->id)
        ->assertJsonPath('data.kind', 'video')
        ->assertJsonPath('data.rendition.name', '360p')
        ->assertJsonPath('data.rendition.size_bytes', 80_000_000)
        ->assertJsonCount(3, 'data.renditions')
        ->assertJsonPath('data.expires_at', now()->addDays(app(ProtectionSettings::class)->offline_license_days)->toIso8601String());

    $data = $response->json('data');
    expect(base64_decode($data['key'], true))->toBe($asset->key())
        ->and($data['playlist'])->toContain(route('lessons.hls.key', ['lesson' => $this->video->uid]))
        ->and($data['watermark']['text'])->not->toContain('+243812345678');

    $license = OfflineLicense::query()->sole();
    expect($license->device_id)->toBe($this->device->id)
        ->and($license->rendition)->toBe('360p')
        ->and($license->revoked_at)->toBeNull();
});

it('renews the same license in place, with the setting in force at renewal', function () {
    offlineLicense($this->video->uid, $this->headers)->assertOk();

    $settings = app(ProtectionSettings::class);
    $settings->offline_license_days = 7;
    $settings->save();
    $this->travel(3)->days();

    offlineLicense($this->video->uid, $this->headers, ['rendition' => '720p'])->assertOk()
        ->assertJsonPath('data.rendition.name', '720p');

    $license = OfflineLicense::query()->sole();
    expect($license->rendition)->toBe('720p')
        ->and($license->expires_at->toIso8601String())->toBe(now()->addDays(7)->startOfSecond()->toIso8601String());

    offlineLicense($this->video->uid, $this->headers, ['rendition' => '1080p'])
        ->assertUnprocessable()->assertJsonPath('code', 'offline.rendition_unavailable');
});

it('is only for the app install that holds the token', function () {
    // No install ID, or another install than the token's device.
    offlineLicense($this->video->uid, bearer($this->token))->assertForbidden()->assertJsonPath('code', 'offline.device_required');
    offlineLicense($this->video->uid, [...bearer($this->token), 'X-Device-Id' => 'install-android-9999'])
        ->assertForbidden()->assertJsonPath('code', 'offline.device_required');

    // A browser session has no registered device.
    freshAuth();
    Sanctum::actingAs($this->device->user);
    $this->postJson("/api/v1/lessons/{$this->video->uid}/offline-license")
        ->assertForbidden()->assertJsonPath('code', 'offline.device_required');

    expect(OfflineLicense::query()->count())->toBe(0);
});

it('needs an active enrollment and a video or audio lesson', function () {
    offlineLicense($this->document->uid, $this->headers)->assertUnprocessable()->assertJsonPath('code', 'playback.not_playable');

    $other = signInMobile('+243822222222', 'install-android-0002')->json('data.token');
    offlineLicense($this->video->uid, [...bearer($other), 'X-Device-Id' => 'install-android-0002'])
        ->assertForbidden()->assertJsonPath('code', 'enrollment.required');

    // A takedown closes the course to enrolled students too (PRD-07 FR-15).
    freshAuth();
    Sanctum::actingAs(actingAsStaff(Role::Admin));
    $this->postJson("/api/v1/admin/courses/{$this->course->id}/take-down", ['note' => 'Plainte'])->assertOk();
    offlineLicense($this->video->uid, $this->headers)->assertForbidden();
});

it('revokes the licenses of a removed device (PRD-07 BR-04)', function () {
    offlineLicense($this->video->uid, $this->headers)->assertOk();

    $this->travel(2)->minutes(); // past the OTP resend cooldown
    $second = signInMobile('+243812345678', 'install-android-0002')->json('data.token');
    freshAuth();
    $this->deleteJson("/api/v1/me/devices/{$this->device->id}", [], [...bearer($second), 'X-Device-Id' => 'install-android-0002'])
        ->assertNoContent();

    expect(OfflineLicense::query()->sole()->revoked_at)->not->toBeNull();
    // Its token is gone: no renewal.
    offlineLicense($this->video->uid, $this->headers)->assertUnauthorized();
});
