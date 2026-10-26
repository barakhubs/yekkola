<?php

declare(strict_types=1);

use App\Domain\Authoring\Enums\CourseStatus;
use App\Domain\Authoring\Enums\VersionState;
use App\Domain\Authoring\Models\CourseVersion;
use App\Domain\Authoring\Models\Lesson;
use App\Domain\Identity\Enums\Role;
use App\Domain\Platform\Models\Activity;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->professor = actingAsProfessor();
    $this->course = courseReadyForReview($this->professor);
    $this->postJson("/api/v1/professor/courses/{$this->course->id}/submit")->assertOk();
    $this->version = $this->course->refresh()->draftVersion;
});

function publish(): void
{
    actingAsStaff();
    test()->postJson('/api/v1/admin/course-versions/'.test()->version->id.'/approve')->assertOk();
    Sanctum::actingAs(test()->professor->user);
}

it('queues versions in review for moderators', function () {
    actingAsStaff(Role::Moderator);

    $this->getJson('/api/v1/admin/course-versions?filter[state]=in_review')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.course.title', 'Mathématiques pour l’Exetat')
        ->assertJsonPath('data.0.is_update', false);

    $this->getJson("/api/v1/admin/course-versions/{$this->version->id}")
        ->assertOk()
        ->assertJsonPath('data.version.sections.0.lessons.1.quiz.questions.0.options.0.is_correct', true);
});

it('tells staff whether they can approve or reject', function () {
    actingAsStaff();
    $can = fn () => $this->getJson("/api/v1/admin/course-versions/{$this->version->id}")->json('data.can');

    expect($can())->toBe(['approve' => true, 'reject' => true]);

    $this->professor->update(['status' => 'suspended']);
    expect($can())->toBe(['approve' => false, 'reject' => true]); // review is paused while suspended

    $this->professor->update(['status' => 'active']);
    $this->postJson("/api/v1/admin/course-versions/{$this->version->id}/approve")->assertOk();
    expect($can())->toBe(['approve' => false, 'reject' => false]);
});

it('approves: the version goes live and the course is published, audited', function () {
    $staff = actingAsStaff();

    $this->postJson("/api/v1/admin/course-versions/{$this->version->id}/approve")->assertOk();

    $course = $this->course->refresh();
    expect($course->status)->toBe(CourseStatus::Published)
        ->and($course->live_version_id)->toBe($this->version->id)
        ->and($course->draft_version_id)->toBeNull()
        ->and($course->published_at)->not->toBeNull()
        ->and($course->total_duration_seconds)->toBe(1_200)
        ->and($this->version->refresh()->state)->toBe(VersionState::Approved)
        ->and(Activity::query()->where('event', 'course_version.approved')->sole()->causer_id)->toBe($staff->id);

    $this->postJson("/api/v1/admin/course-versions/{$this->version->id}/approve")
        ->assertStatus(409);
});

it('rejects with notes: the draft becomes editable again', function () {
    actingAsStaff();

    $this->postJson("/api/v1/admin/course-versions/{$this->version->id}/reject")->assertUnprocessable();
    $this->postJson("/api/v1/admin/course-versions/{$this->version->id}/reject", ['note' => 'La vidéo 1 est floue.'])->assertOk();

    expect($this->course->refresh()->status)->toBe(CourseStatus::Draft);

    Sanctum::actingAs($this->professor->user);
    $this->getJson("/api/v1/professor/courses/{$this->course->id}")->assertJsonPath('data.draft_version.review_notes', 'La vidéo 1 est floue.');
    $this->patchJson("/api/v1/professor/courses/{$this->course->id}/draft", ['subtitle' => 'Corrigé'])->assertOk();
});

it('forbids reviewing your own course', function () {
    $this->professor->user->assignRole(Role::Moderator->value);
    Sanctum::actingAs($this->professor->user);

    $this->postJson("/api/v1/admin/course-versions/{$this->version->id}/approve")
        ->assertForbidden()
        ->assertJsonPath('code', 'course.own_course');
});

it('copies the live version into a new draft, keeping lesson uids and reusing media', function () {
    publish();
    $live = $this->version;

    $draft = $this->postJson("/api/v1/professor/courses/{$this->course->id}/draft")->assertCreated()->json('data');

    $liveLessons = Lesson::query()->where('course_version_id', $live->id)->orderBy('position')->get();
    $draftLessons = Lesson::query()->where('course_version_id', $draft['id'])->orderBy('position')->get();

    expect($draft['number'])->toBe(2)
        ->and($draftLessons->pluck('uid')->all())->toBe($liveLessons->pluck('uid')->all())
        ->and($draftLessons->pluck('id')->intersect($liveLessons->pluck('id'))->all())->toBe([])
        ->and($draftLessons[2]->media_asset_id)->toBe($liveLessons[2]->media_asset_id)
        ->and($draftLessons[0]->getFirstMedia('document'))->not->toBeNull()
        ->and($draftLessons[1]->quiz()->first()->questions()->count())->toBe(1)
        ->and(CourseVersion::query()->find($draft['id'])->coverUrl())->not->toBeNull();

    $this->postJson("/api/v1/professor/courses/{$this->course->id}/draft")->assertStatus(409)->assertJsonPath('code', 'course.draft_exists');
});

it('keeps the live version unchanged while a new draft is edited, then supersedes it', function () {
    publish();
    $this->postJson("/api/v1/professor/courses/{$this->course->id}/draft")->assertCreated();
    $this->patchJson("/api/v1/professor/courses/{$this->course->id}/draft", ['title' => 'Mathématiques – édition 2027'])->assertOk();

    expect($this->course->refresh()->liveVersion->title)->toBe('Mathématiques pour l’Exetat')
        ->and($this->course->status)->toBe(CourseStatus::Published);

    $this->postJson("/api/v1/professor/courses/{$this->course->id}/submit")->assertOk();
    $newVersion = $this->course->refresh()->draftVersion;

    actingAsStaff();
    $this->getJson("/api/v1/admin/course-versions/{$newVersion->id}")
        ->assertJsonPath('data.is_update', true)
        ->assertJsonPath('data.changes.added', []);
    $this->postJson("/api/v1/admin/course-versions/{$newVersion->id}/approve")->assertOk();

    expect($this->version->refresh()->state)->toBe(VersionState::Superseded)
        ->and($this->course->refresh()->liveVersion->title)->toBe('Mathématiques – édition 2027');
});

it('does not approve while the professor is suspended', function () {
    $this->professor->update(['status' => 'suspended']);
    actingAsStaff();

    $this->postJson("/api/v1/admin/course-versions/{$this->version->id}/approve")
        ->assertForbidden()
        ->assertJsonPath('code', 'professor.suspended');

    expect($this->version->refresh()->state)->toBe(VersionState::InReview);
});

it('keeps an archived course archived when an update is approved', function () {
    publish();
    $this->postJson("/api/v1/professor/courses/{$this->course->id}/archive")->assertOk();
    $this->postJson("/api/v1/professor/courses/{$this->course->id}/draft")->assertCreated();
    $this->postJson("/api/v1/professor/courses/{$this->course->id}/submit")->assertOk();

    actingAsStaff();
    $this->postJson('/api/v1/admin/course-versions/'.$this->course->refresh()->draft_version_id.'/approve')->assertOk();

    expect($this->course->refresh()->status)->toBe(CourseStatus::Archived);
});

it('keeps deleted courses out of the review queue', function () {
    $this->deleteJson("/api/v1/professor/courses/{$this->course->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'course.invalid_transition');

    actingAsStaff();
    $this->postJson("/api/v1/admin/course-versions/{$this->version->id}/reject", ['note' => 'Incomplet.'])->assertOk();
    Sanctum::actingAs($this->professor->user);
    $this->deleteJson("/api/v1/professor/courses/{$this->course->id}")->assertNoContent();

    actingAsStaff();
    $this->getJson('/api/v1/admin/course-versions')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/admin/course-versions/{$this->version->id}")->assertNotFound();
});

it('unpublishes, republishes without review, and archives', function () {
    publish();

    $this->postJson("/api/v1/professor/courses/{$this->course->id}/unpublish")->assertOk()->assertJsonPath('data.status', 'unpublished');
    $this->postJson("/api/v1/professor/courses/{$this->course->id}/unpublish")->assertStatus(409);
    $this->postJson("/api/v1/professor/courses/{$this->course->id}/republish")->assertOk()->assertJsonPath('data.status', 'published');
    $this->postJson("/api/v1/professor/courses/{$this->course->id}/archive")->assertOk()->assertJsonPath('data.status', 'archived');
});

it('tells the studio which lifecycle actions apply now', function () {
    $can = fn () => $this->getJson("/api/v1/professor/courses/{$this->course->id}")->json('data.can');

    // In review, never published: only withdraw.
    expect($can())->toBe([
        'start_draft' => false, 'submit' => false, 'withdraw' => true,
        'unpublish' => false, 'republish' => false, 'archive' => false, 'delete' => false,
    ]);

    publish();
    expect($can())->toMatchArray(['start_draft' => true, 'unpublish' => true, 'archive' => true, 'republish' => false, 'delete' => false]);

    $this->postJson("/api/v1/professor/courses/{$this->course->id}/unpublish")->assertOk();
    expect($can())->toMatchArray(['unpublish' => false, 'republish' => true, 'archive' => true]);

    $this->postJson("/api/v1/professor/courses/{$this->course->id}/draft")->assertCreated();
    expect($can())->toMatchArray(['start_draft' => false, 'submit' => true, 'withdraw' => false]);
});

it('applies price changes immediately, without review', function () {
    publish();

    $this->patchJson("/api/v1/professor/courses/{$this->course->id}", ['price_minor' => 2_000, 'currency' => 'USD'])
        ->assertOk()
        ->assertJsonPath('data.price', ['amount_minor' => 2_000, 'currency' => 'USD'])
        ->assertJsonPath('data.status', 'published');
});
