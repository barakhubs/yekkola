<?php

declare(strict_types=1);

use App\Domain\Authoring\Models\Lesson;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\ReviewStatus;
use App\Domain\Learning\Models\Review;
use App\Domain\Moderation\Models\Report;
use App\Domain\Platform\Models\Activity;
use App\Domain\Professors\Enums\ProfessorStatus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->professor = actingAsProfessor('Marie Kabila');
    $this->course = publishedCourse($this->professor, 'Algèbre pour l’Exetat', ['is_free' => true, 'price_minor' => null, 'currency' => null]);
    $document = Lesson::query()->where('course_version_id', $this->course->live_version_id)->orderBy('position')->firstOrFail();

    $this->author = User::factory()->create(['name' => 'Jean']);
    Sanctum::actingAs($this->author);
    $this->postJson("/api/v1/courses/{$this->course->id}/enroll")->assertOk();
    $this->putJson('/api/v1/me/progress', ['items' => [['lesson_uid' => $document->uid, 'completed' => true]]])->assertOk();
    $this->postJson("/api/v1/courses/{$this->course->id}/review", ['rating' => 1, 'body' => 'Insultes.'])->assertCreated();
    $this->review = Review::query()->sole();
});

function fileReport(User $as, string $type, string $id, string $reason = 'abusive'): Illuminate\Testing\TestResponse
{
    app('auth')->forgetGuards();
    Sanctum::actingAs($as);

    return test()->postJson('/api/v1/reports', ['type' => $type, 'id' => $id, 'reason' => $reason]);
}

it('files one open report per person and item, never on their own content or hidden items', function () {
    $grace = User::factory()->create();
    $first = fileReport($grace, 'review', $this->review->id)->assertCreated()->json('data.id');
    fileReport($grace, 'review', $this->review->id, 'spam')->assertOk()->assertJsonPath('data.id', $first)->assertJsonPath('data.reason', 'abusive');

    fileReport($this->author, 'review', $this->review->id)->assertUnprocessable()->assertJsonPath('code', 'report.own_content');
    fileReport($this->professor->user, 'course', $this->course->id)->assertUnprocessable();
    fileReport($grace, 'professor', $this->professor->id)->assertCreated();
    fileReport($grace, 'course', (string) Str::ulid())->assertNotFound();
    app('auth')->forgetGuards();
    Sanctum::actingAs($grace);
    $this->postJson('/api/v1/reports', ['type' => 'course', 'id' => $this->course->id, 'reason' => 'other'])->assertUnprocessable();

    $this->review->forceFill(['status' => ReviewStatus::Hidden])->save();
    fileReport(User::factory()->create(), 'review', $this->review->id)->assertNotFound();
    expect(Report::query()->count())->toBe(2);
});

it('closes every open report on an item with one decision, once', function () {
    fileReport(User::factory()->create(), 'review', $this->review->id)->assertCreated();
    $report = Report::query()->sole();
    fileReport(User::factory()->create(), 'review', $this->review->id, 'spam')->assertCreated();

    app('auth')->forgetGuards();
    actingAsStaff(Role::Moderator);
    $this->getJson('/api/v1/admin/reports')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.open_reports_on_target', 2)
        ->assertJsonPath('data.0.target.author.name', 'Jean')
        ->assertJsonPath('data.0.can', ['dismiss' => true, 'hide' => true, 'warn' => true, 'suspend' => false]);

    $this->postJson("/api/v1/admin/reports/{$report->id}/resolve", ['action' => 'hide'])->assertUnprocessable();
    $this->postJson("/api/v1/admin/reports/{$report->id}/resolve", ['action' => 'suspend', 'note' => 'Récidive'])->assertForbidden();
    $this->postJson("/api/v1/admin/reports/{$report->id}/resolve", ['action' => 'hide', 'note' => 'Propos injurieux'])->assertOk()
        ->assertJsonPath('data.status', 'actioned')
        ->assertJsonPath('data.target.state', 'hidden');
    $this->postJson("/api/v1/admin/reports/{$report->id}/resolve", ['action' => 'dismiss'])->assertConflict();

    expect($this->review->refresh()->status)->toBe(ReviewStatus::Hidden)
        ->and(Report::query()->where('status', 'actioned')->count())->toBe(2)
        ->and(Activity::query()->where('event', 'report.resolved')->count())->toBe(1);
    $this->getJson('/api/v1/admin/reports')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/admin/reports?filter[status]=all')->assertOk()->assertJsonCount(2, 'data');
});

it('lets each action need the permission of its own screen', function () {
    fileReport(User::factory()->create(), 'course', $this->course->id, 'copyright')->assertCreated();
    fileReport(User::factory()->create(), 'review', $this->review->id)->assertCreated();
    [$courseReport, $reviewReport] = [Report::query()->where('reportable_type', 'course')->sole(), Report::query()->where('reportable_type', 'review')->sole()];

    app('auth')->forgetGuards();
    actingAsStaff(Role::Moderator);
    $this->postJson("/api/v1/admin/reports/{$courseReport->id}/resolve", ['action' => 'hide', 'note' => 'Contenu copié'])->assertForbidden();
    $this->postJson("/api/v1/admin/reports/{$reviewReport->id}/resolve", ['action' => 'warn', 'note' => 'Premier avertissement'])->assertOk();
    expect(Activity::query()->where('event', 'user.warned')->sole()->subject_id)->toBe($this->author->id);

    app('auth')->forgetGuards();
    actingAsStaff(Role::Admin);
    $this->postJson("/api/v1/admin/reports/{$courseReport->id}/resolve", ['action' => 'suspend', 'note' => 'Contenu copié'])->assertOk()
        ->assertJsonPath('data.action', 'suspend');
    expect($this->professor->refresh()->status)->toBe(ProfessorStatus::Suspended)
        ->and($this->author->refresh()->status)->toBe(UserStatus::Active);

    app('auth')->forgetGuards();
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/admin/reports')->assertForbidden();
});
