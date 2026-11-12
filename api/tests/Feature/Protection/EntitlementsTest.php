<?php

declare(strict_types=1);

use App\Domain\Authoring\Models\Lesson;
use App\Domain\Authoring\Models\MediaAsset;
use App\Domain\Identity\Enums\Role;
use App\Domain\Protection\Models\OfflineLicense;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->course = publishedCourse(actingAsProfessor(), 'Algèbre pour l’Exetat', ['is_free' => true, 'price_minor' => null, 'currency' => null]);
    $this->video = Lesson::query()->where('course_version_id', $this->course->live_version_id)->orderBy('position')->get()[2];
    freshAuth();

    $token = signInMobile('+243812345678', 'install-android-0001')->json('data.token');
    $this->headers = [...bearer($token), 'X-Device-Id' => 'install-android-0001'];
    freshAuth();
    $this->postJson("/api/v1/courses/{$this->course->id}/enroll", [], $this->headers)->assertOk();
    freshAuth();
    $this->postJson("/api/v1/lessons/{$this->video->uid}/offline-license", [], $this->headers)->assertOk();
});

function entitlements(array $headers): Illuminate\Testing\TestResponse
{
    freshAuth();

    return test()->getJson('/api/v1/me/entitlements', $headers);
}

it('keeps a fresh license, then asks for renewal past half its duration and after expiry (PRD-07 FR-10)', function () {
    $license = OfflineLicense::query()->sole();

    entitlements($this->headers)->assertOk()
        ->assertJsonPath('data.licenses.0.license_id', $license->id)
        ->assertJsonPath('data.licenses.0.lesson_uid', $this->video->uid)
        ->assertJsonPath('data.licenses.0.media_id', $license->media_asset_id)
        ->assertJsonPath('data.licenses.0.current_media_id', $license->media_asset_id)
        ->assertJsonPath('data.licenses.0.action', 'keep')
        ->assertJsonPath('data.licenses.0.reason', null);

    // Default duration 14 days: renew once fewer than 7 are left.
    $this->travel(8)->days();
    entitlements($this->headers)->assertJsonPath('data.licenses.0.action', 'renew')->assertJsonPath('data.licenses.0.reason', 'expiring');

    $this->travel(7)->days();
    entitlements($this->headers)->assertJsonPath('data.licenses.0.action', 'renew')->assertJsonPath('data.licenses.0.reason', 'expired');
});

it('tells the app to delete when the license is revoked, access is gone, or the media changed', function () {
    $license = OfflineLicense::query()->sole();

    // New media for the lesson (a re-uploaded video in a new live version).
    $other = MediaAsset::query()->findOrFail($this->video->media_asset_id)->replicate()->fill(['provider_upload_id' => 'fake-upload-replaced']);
    $other->save();
    Lesson::query()->whereKey($this->video->id)->update(['media_asset_id' => $other->id]);
    entitlements($this->headers)->assertJsonPath('data.licenses.0.action', 'delete')
        ->assertJsonPath('data.licenses.0.reason', 'media_changed')
        ->assertJsonPath('data.licenses.0.current_media_id', $other->id);
    Lesson::query()->whereKey($this->video->id)->update(['media_asset_id' => $license->media_asset_id]);

    // Revoked (e.g. the account was deleted, or the device removed then registered again).
    $license->forceFill(['revoked_at' => now()])->save();
    entitlements($this->headers)->assertJsonPath('data.licenses.0.action', 'delete')->assertJsonPath('data.licenses.0.reason', 'revoked');
    $license->forceFill(['revoked_at' => null])->save();

    // Takedown: access is gone for enrolled students too (PRD-07 FR-15).
    freshAuth();
    Sanctum::actingAs(actingAsStaff(Role::Admin));
    $this->postJson("/api/v1/admin/courses/{$this->course->id}/take-down", ['note' => 'Plainte'])->assertOk();
    entitlements($this->headers)->assertJsonPath('data.licenses.0.action', 'delete')->assertJsonPath('data.licenses.0.reason', 'no_access');
});

it('is only for the registered device that holds the token, and lists only its licenses', function () {
    entitlements(array_diff_key($this->headers, ['X-Device-Id' => true]))
        ->assertForbidden()->assertJsonPath('code', 'offline.device_required');

    $this->travel(2)->minutes(); // past the OTP resend cooldown
    $second = signInMobile('+243812345678', 'install-android-0002')->json('data.token');
    entitlements([...bearer($second), 'X-Device-Id' => 'install-android-0002'])->assertOk()->assertJsonCount(0, 'data.licenses');
});
