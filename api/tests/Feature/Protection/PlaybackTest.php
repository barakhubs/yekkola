<?php

declare(strict_types=1);

use App\Domain\Authoring\Models\Lesson;
use App\Domain\Authoring\Models\MediaAsset;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Settings\ProtectionSettings;
use App\Domain\Protection\Models\StreamSession;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->professor = actingAsProfessor();
    $this->course = publishedCourse($this->professor, 'Algèbre pour l’Exetat', ['is_free' => true, 'price_minor' => null, 'currency' => null]);
    $lessons = Lesson::query()->where('course_version_id', $this->course->live_version_id)->orderBy('position')->get();
    [$this->document, $this->quiz, $this->video] = [$lessons[0], $lessons[1], $lessons[2]];

    $this->student = User::factory()->create(['name' => 'Grâce Mbuyi', 'phone_e164' => '+243812345678']);
    Sanctum::actingAs($this->student);
    $this->postJson("/api/v1/courses/{$this->course->id}/enroll")->assertOk();
});

function play(string $uid): Illuminate\Testing\TestResponse
{
    return test()->postJson("/api/v1/lessons/{$uid}/playback");
}

function heartbeat(string $uid, string $sessionId): Illuminate\Testing\TestResponse
{
    return test()->postJson("/api/v1/lessons/{$uid}/playback/heartbeat", ['session_id' => $sessionId]);
}

it('gives an enrolled student signed DRM tokens and a masked watermark — never a media URL', function () {
    $response = play($this->video->uid)->assertOk()
        ->assertJsonPath('data.kind', 'video')
        ->assertJsonPath('data.is_preview', false)
        ->assertJsonPath('data.watermark.text', 'Grâce M. · '.$this->student->maskedPhone())
        ->assertJsonPath('data.stream.heartbeat_seconds', 30);

    $data = $response->json('data');
    expect($data['playback_id'])->toBe(MediaAsset::query()->sole()->playback_id)
        ->and($data['tokens']['playback'])->toStartWith('fake-playback-token:')
        ->and($data['tokens']['drm'])->toStartWith('fake-drm-token:')
        ->and(json_encode($data))->not->toContain('+243812345678')
        ->and(json_encode($data))->not->toContain('.m3u8')
        ->and(json_encode($data))->not->toContain('.mp4');

    play($this->document->uid)->assertUnprocessable()->assertJsonPath('code', 'playback.not_playable');
    $this->postJson('/api/v1/lessons/01JZZZZZZZZZZZZZZZZZZZZZZZ/playback')->assertNotFound();
});

it('refuses students who are not enrolled, and media still processing', function () {
    Sanctum::actingAs(User::factory()->create());
    play($this->video->uid)->assertForbidden()->assertJsonPath('code', 'enrollment.required');

    Sanctum::actingAs($this->student);
    MediaAsset::query()->update(['status' => 'processing']);
    play($this->video->uid)->assertConflict()->assertJsonPath('code', 'playback.media_not_ready');
});

it('plays preview lessons of listed courses for anyone, without a name in the watermark', function () {
    Lesson::query()->whereKey($this->video->id)->update(['is_preview' => true]);
    app('auth')->forgetGuards();

    $this->postJson("/api/v1/lessons/{$this->video->uid}/preview-playback")->assertOk()
        ->assertJsonPath('data.is_preview', true)
        ->assertJsonPath('data.watermark', null)
        ->assertJsonPath('data.stream', null);

    Lesson::query()->whereKey($this->video->id)->update(['is_preview' => false]);
    $this->postJson("/api/v1/lessons/{$this->video->uid}/preview-playback")->assertForbidden()->assertJsonPath('code', 'playback.preview_only');

    // A preview of an unpublished course is not public.
    Lesson::query()->whereKey($this->video->id)->update(['is_preview' => true]);
    $this->course->update(['status' => 'unpublished']);
    $this->postJson("/api/v1/lessons/{$this->video->uid}/preview-playback")->assertForbidden();
});

it('limits concurrent web streams: the newest wins and the older player is told to stop', function () {
    $first = play($this->video->uid)->json('data.stream.session_id');
    heartbeat($this->video->uid, $first)->assertOk()->assertJsonPath('data.active', true);

    $second = play($this->video->uid)->json('data.stream.session_id');
    heartbeat($this->video->uid, $first)->assertConflict()->assertJsonPath('code', 'playback.stream_replaced');
    heartbeat($this->video->uid, $second)->assertOk();

    // With two streams allowed, both stay live.
    $settings = app(ProtectionSettings::class);
    $settings->concurrent_web_streams = 2;
    $settings->save();
    $third = play($this->video->uid)->json('data.stream.session_id');
    heartbeat($this->video->uid, $second)->assertOk();
    heartbeat($this->video->uid, $third)->assertOk();

    // A stream that went quiet doesn't count, and can't come back over the limit.
    $settings->concurrent_web_streams = 1;
    $settings->save();
    $this->travel(2)->minutes();
    $fourth = play($this->video->uid)->json('data.stream.session_id');
    heartbeat($this->video->uid, $third)->assertConflict();
    heartbeat($this->video->uid, $fourth)->assertOk();

    // Someone else's session is not found.
    Sanctum::actingAs(User::factory()->create());
    heartbeat($this->video->uid, $fourth)->assertNotFound();
});

it('does not count mobile apps toward the web stream limit', function () {
    $token = signInMobile('+243812345678')->json('data.token');
    freshAuth();

    $this->postJson("/api/v1/lessons/{$this->video->uid}/playback", [], bearer($token))->assertOk()
        ->assertJsonPath('data.stream', null);
    expect(StreamSession::query()->count())->toBe(0);
});

it('stops tokens for revoked enrollments, keeps heartbeats to their own lesson, and gives audio no DRM', function () {
    $first = play($this->video->uid)->json('data.stream.session_id');
    heartbeat($this->document->uid, $first)->assertNotFound();

    App\Domain\Learning\Models\Enrollment::query()->update(['status' => 'revoked']);
    play($this->video->uid)->assertForbidden()->assertJsonPath('code', 'enrollment.required');

    App\Domain\Learning\Models\Enrollment::query()->update(['status' => 'active']);
    MediaAsset::query()->update(['kind' => 'audio']);
    play($this->video->uid)->assertOk()
        ->assertJsonPath('data.kind', 'audio')
        ->assertJsonPath('data.tokens.drm', null)
        ->assertJsonPath('data.tokens.thumbnail', null);
});

it('resumes a stream that went quiet while it still fits the limit', function () {
    $session = play($this->video->uid)->json('data.stream.session_id');

    $this->travel(2)->minutes();
    heartbeat($this->video->uid, $session)->assertOk();
    expect(StreamSession::query()->sole()->ended_at)->toBeNull();
});

it('blocks previews when the professor is suspended', function () {
    Lesson::query()->whereKey($this->video->id)->update(['is_preview' => true]);
    $this->professor->forceFill(['status' => 'suspended'])->save();
    app('auth')->forgetGuards();

    $this->postJson("/api/v1/lessons/{$this->video->uid}/preview-playback")->assertForbidden();
});

it('ends live streams when the account is deleted', function () {
    play($this->video->uid)->assertOk();

    event(new App\Domain\Identity\Events\UserAnonymizing($this->student));
    expect(StreamSession::query()->sole()->ended_at)->not->toBeNull();
});
