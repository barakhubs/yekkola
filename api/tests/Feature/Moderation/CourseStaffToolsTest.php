<?php

declare(strict_types=1);

use App\Domain\Authoring\Models\Lesson;
use App\Domain\Authoring\Models\MediaAsset;
use App\Domain\Commerce\Models\Bundle;
use App\Domain\Commerce\Models\Coupon;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Platform\Models\Activity;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->from = actingAsProfessor('Marie Kabongo');
    $this->course = publishedCourse($this->from, 'Algèbre', ['is_free' => false, 'price_minor' => 1_500, 'currency' => 'USD']);
    $this->other = publishedCourse($this->from, 'Géométrie', ['is_free' => false, 'price_minor' => 1_000, 'currency' => 'USD']);
    $this->to = actingAsProfessor('Jean-Paul Mukendi');
});

it('moves a course to another professor, switching off the old owner’s coupons and bundles (super admin only)', function () {
    $coupon = Coupon::query()->create([
        'code' => 'ALGEBRE20', 'scope' => 'course', 'course_id' => $this->course->id, 'professor_profile_id' => $this->from->id,
        'discount_type' => 'percent', 'value' => 20, 'funded_by' => 'professor', 'is_active' => true,
    ]);
    $bundle = Bundle::query()->create([
        'professor_profile_id' => $this->from->id, 'slug' => 'pack-maths', 'title' => 'Pack maths',
        'price_minor' => 2_000, 'currency' => 'USD', 'status' => 'active',
    ]);
    $bundle->courses()->attach([$this->course->id => ['position' => 1], $this->other->id => ['position' => 2]]);
    $asset = Lesson::query()->where('course_version_id', $this->course->live_version_id)->whereNotNull('media_asset_id')->value('media_asset_id');

    actingAsStaff(Role::Admin);
    $this->postJson("/api/v1/admin/courses/{$this->course->id}/transfer", ['professor_profile_id' => $this->to->id, 'note' => 'Co-auteur'])
        ->assertForbidden();

    actingAsStaff(Role::SuperAdmin);
    $this->postJson("/api/v1/admin/courses/{$this->course->id}/transfer", ['professor_profile_id' => $this->from->id, 'note' => 'Même professeur'])
        ->assertUnprocessable()->assertJsonPath('code', 'course.invalid_transfer_target');
    $this->to->update(['status' => 'suspended']);
    $this->postJson("/api/v1/admin/courses/{$this->course->id}/transfer", ['professor_profile_id' => $this->to->id, 'note' => 'Suspendu'])
        ->assertUnprocessable();
    $this->to->update(['status' => 'active']);

    $this->postJson("/api/v1/admin/courses/{$this->course->id}/transfer", ['professor_profile_id' => $this->to->id, 'note' => 'Co-auteur parti'])
        ->assertOk()->assertJsonPath('data.can.transfer', true);

    expect($this->course->fresh()->professor_profile_id)->toBe($this->to->id)
        ->and($coupon->fresh()->is_active)->toBeFalse()
        ->and($bundle->fresh()->status->value)->toBe('archived')
        ->and($bundle->courses()->pluck('courses.id')->all())->toBe([$this->other->id])
        ->and(MediaAsset::query()->find($asset)->professor_profile_id)->toBe($this->to->id)
        ->and($this->other->fresh()->professor_profile_id)->toBe($this->from->id)
        ->and(Activity::query()->where('event', 'course.transferred')->sole()->properties['reason'])->toBe('Co-auteur parti');
});

it('clears a course-level revenue share on transfer and respects the new owner’s free-course limit', function () {
    $this->course->update(['revenue_share_bps' => 8_500]);
    actingAsStaff(Role::SuperAdmin);
    $this->postJson("/api/v1/admin/courses/{$this->course->id}/transfer", ['professor_profile_id' => $this->to->id, 'note' => 'Reprise'])->assertOk();
    expect($this->course->fresh()->revenue_share_bps)->toBeNull()
        ->and(Activity::query()->where('event', 'course.transferred')->sole()->properties['course_revenue_share_bps_cleared'])->toBe(8_500);

    $settings = app(App\Domain\Platform\Settings\CatalogSettings::class);
    $settings->free_course_limit = 0;
    $settings->save();
    $this->other->update(['is_free' => true, 'price_minor' => null, 'currency' => null]);
    $this->postJson("/api/v1/admin/courses/{$this->other->id}/transfer", ['professor_profile_id' => $this->to->id, 'note' => 'Reprise'])
        ->assertUnprocessable()->assertJsonPath('code', 'course.free_limit_reached');
    expect($this->other->fresh()->professor_profile_id)->toBe($this->from->id);
});

it('grants an account access to a course, once, with a reason', function () {
    $student = User::factory()->create();

    actingAsStaff(Role::Moderator);
    $this->postJson("/api/v1/admin/users/{$student->id}/enrollments", ['course_id' => $this->course->id, 'note' => 'Payé hors ligne'])
        ->assertForbidden();

    actingAsStaff(Role::Admin);
    $this->postJson("/api/v1/admin/users/{$student->id}/enrollments", ['course_id' => $this->course->id])
        ->assertUnprocessable()->assertJsonValidationErrors('note');
    $this->postJson("/api/v1/admin/users/{$student->id}/enrollments", ['course_id' => $this->course->id, 'note' => 'Payé hors ligne'])
        ->assertOk()->assertJsonPath('data.enrollment_count', 1);
    $this->postJson("/api/v1/admin/users/{$student->id}/enrollments", ['course_id' => $this->course->id, 'note' => 'Encore'])
        ->assertConflict()->assertJsonPath('code', 'enrollment.already_active');

    expect(Enrollment::query()->where('user_id', $student->id)->sole()->source->value)->toBe('admin')
        ->and(Activity::query()->where('event', 'enrollment.granted')->sole()->properties['reason'])->toBe('Payé hors ligne');

    $banned = User::factory()->create(['status' => 'banned']);
    $this->postJson("/api/v1/admin/users/{$banned->id}/enrollments", ['course_id' => $this->course->id, 'note' => 'Essai'])
        ->assertConflict()->assertJsonPath('code', 'enrollment.account_banned');

    $this->course->update(['taken_down_at' => now()]);
    $this->postJson('/api/v1/admin/users/'.User::factory()->create()->id.'/enrollments', ['course_id' => $this->course->id, 'note' => 'Essai'])
        ->assertStatus(403)->assertJsonPath('code', 'enrollment.course_taken_down');
});

it('lets reviewers play a lesson of the version they review, and nobody else', function () {
    $version = $this->course->liveVersion;
    $video = Lesson::query()->where('course_version_id', $version->id)->where('type', 'video')->sole();
    $document = Lesson::query()->where('course_version_id', $version->id)->where('type', 'document')->sole();
    $assetId = (string) $video->media_asset_id;

    // A student or a professor: no staff preview.
    $this->getJson("/api/v1/admin/course-versions/{$version->id}/lessons/{$video->id}/preview")->assertForbidden();
    $this->get("/api/v1/admin/media/{$assetId}/hls/key")->assertForbidden();

    actingAsStaff(Role::Moderator);
    $preview = $this->getJson("/api/v1/admin/course-versions/{$version->id}/lessons/{$video->id}/preview")->assertOk()
        ->assertJsonPath('data.kind', 'video')->json('data');
    expect($preview['playlist_url'])->toBe(route('admin.media-previews.master', $assetId));

    $master = (string) $this->get("/api/v1/admin/media/{$assetId}/hls/master.m3u8")->assertOk()->getContent();
    expect($master)->toContain('/admin/media/'.$assetId.'/hls/720p.m3u8');
    $media = (string) $this->get("/api/v1/admin/media/{$assetId}/hls/720p.m3u8")->assertOk()->getContent();
    expect($media)->toContain('/admin/media/'.$assetId.'/hls/key');
    expect($this->get("/api/v1/admin/media/{$assetId}/hls/key")->assertOk()->getContent())
        ->toBe(MediaAsset::query()->find($assetId)->key());

    $this->getJson("/api/v1/admin/course-versions/{$version->id}/lessons/{$document->id}/preview")->assertOk()
        ->assertJsonPath('data.kind', 'document')
        ->assertJsonPath('data.document_url', route('admin.lesson-previews.document', [$version->id, $document->id]));
    $this->get("/api/v1/admin/course-versions/{$version->id}/lessons/{$document->id}/document")->assertOk();
    expect(Activity::query()->where('event', 'lesson.document_previewed')->count())->toBe(1);
});
