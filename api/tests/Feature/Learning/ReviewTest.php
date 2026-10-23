<?php

declare(strict_types=1);

use App\Domain\Authoring\Models\Lesson;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\Review;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->professor = actingAsProfessor('Marie Kabila');
    $this->course = publishedCourse($this->professor, 'Algèbre pour l’Exetat', ['is_free' => true, 'price_minor' => null, 'currency' => null]);
    $this->document = Lesson::query()->where('course_version_id', $this->course->live_version_id)->orderBy('position')->firstOrFail();
});

/** Signs in a new enrolled student who completed the document (33 %, past the 20 % default threshold). */
function reviewer(string $name = 'Grâce Mbuyi', bool $progress = true): User
{
    $student = User::factory()->create(['name' => $name]);
    Sanctum::actingAs($student);
    test()->postJson('/api/v1/courses/'.test()->course->id.'/enroll')->assertOk();
    if ($progress) {
        test()->putJson('/api/v1/me/progress', ['items' => [['lesson_uid' => test()->document->uid, 'completed' => true]]])->assertOk();
    }

    return $student;
}

it('lets an enrolled student past the threshold post, edit, and delete one review', function () {
    reviewer();
    $url = "/api/v1/courses/{$this->course->id}/review";

    $this->postJson($url, ['rating' => 4, 'body' => 'Très clair.'])->assertCreated()
        ->assertJsonPath('data.rating', 4)
        ->assertJsonPath('data.author.name', 'Grâce M.')
        ->assertJsonPath('data.status', 'visible');
    $this->postJson($url, ['rating' => 5])->assertConflict()->assertJsonPath('code', 'review.exists');

    $this->travel(1)->minutes();
    $this->patchJson($url, ['rating' => 5, 'body' => ''])->assertOk()
        ->assertJsonPath('data.rating', 5)
        ->assertJsonPath('data.body', null)
        ->assertJsonPath('data.edited_at', fn ($v) => $v !== null);
    $this->getJson($url)->assertOk()->assertJsonPath('data.rating', 5);
    expect($this->course->refresh()->rating_count)->toBe(1)->and($this->course->rating_avg)->toBe(5.0);
    $this->getJson("/api/v1/me/courses/{$this->course->id}")->assertJsonPath('data.has_reviewed', true);

    $this->deleteJson($url)->assertNoContent();
    $this->getJson($url)->assertNotFound();
    $this->patchJson($url, ['rating' => 3])->assertNotFound();
    expect($this->course->refresh()->rating_count)->toBe(0);
});

it('refuses reviews from non-enrolled students, below the threshold, and on my own course', function () {
    $url = "/api/v1/courses/{$this->course->id}/review";

    $this->postJson($url, ['rating' => 5])->assertForbidden()->assertJsonPath('code', 'review.own_course'); // the professor

    Sanctum::actingAs(User::factory()->create());
    $this->postJson($url, ['rating' => 5])->assertForbidden()->assertJsonPath('code', 'enrollment.required');

    reviewer(progress: false);
    $this->postJson($url, ['rating' => 5])->assertUnprocessable()
        ->assertJsonPath('code', 'review.progress_too_low')
        ->assertJsonPath('min_progress_pct', 20);
    $this->postJson($url, ['rating' => 6])->assertUnprocessable()->assertJsonValidationErrors('rating');
});

it('lists published reviews publicly and keeps ratings in step, without banned authors', function () {
    reviewer('Grâce Mbuyi');
    $this->postJson("/api/v1/courses/{$this->course->id}/review", ['rating' => 5, 'body' => 'Excellent.'])->assertCreated();
    $banned = reviewer('Paul Ilunga');
    $this->postJson("/api/v1/courses/{$this->course->id}/review", ['rating' => 1, 'body' => 'Nul.'])->assertCreated();
    reviewer('Josué');
    $this->postJson("/api/v1/courses/{$this->course->id}/review", ['rating' => 3])->assertCreated();

    expect($this->course->refresh()->rating_count)->toBe(3)
        ->and($this->professor->refresh()->rating_count)->toBe(3);

    $banned->forceFill(['status' => UserStatus::Banned])->save();
    expect($this->course->refresh()->rating_count)->toBe(2)
        ->and($this->course->rating_avg)->toBe(4.0)
        ->and((float) $this->professor->refresh()->rating_avg)->toBe(4.0);

    Review::query()->where('rating', 3)->update(['status' => 'hidden']);
    $this->getJson("/api/v1/courses/{$this->course->slug}/reviews")->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.author.name', 'Grâce M.')
        ->assertJsonMissingPath('data.0.status');
    $this->getJson("/api/v1/courses/{$this->course->slug}/reviews?filter[rating]=1")->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/courses/nope/reviews')->assertNotFound();
});

it('lets the professor list reviews of their courses and reply publicly', function () {
    reviewer();
    $id = $this->postJson("/api/v1/courses/{$this->course->id}/review", ['rating' => 4])->json('data.id');

    $professorUser = $this->professor->user;
    Sanctum::actingAs($professorUser);
    $this->getJson('/api/v1/professor/reviews?filter[replied]=false')->assertOk()
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.course.slug', $this->course->slug);

    $this->postJson("/api/v1/professor/reviews/{$id}/reply", ['reply' => 'Merci Grâce !'])->assertOk()
        ->assertJsonPath('data.reply.body', 'Merci Grâce !');
    $this->getJson('/api/v1/professor/reviews?filter[replied]=false')->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/courses/{$this->course->slug}/reviews")->assertJsonPath('data.0.reply.body', 'Merci Grâce !');

    $this->postJson("/api/v1/professor/reviews/{$id}/reply", ['reply' => null])->assertOk()->assertJsonPath('data.reply', null);

    // Another professor can't reply.
    actingAsProfessor('Jean Mukendi');
    $this->postJson("/api/v1/professor/reviews/{$id}/reply", ['reply' => 'Spam'])->assertNotFound();
});

it('removes my reviews when my account is deleted', function () {
    $student = reviewer();
    $this->postJson("/api/v1/courses/{$this->course->id}/review", ['rating' => 5])->assertCreated();

    event(new App\Domain\Identity\Events\UserAnonymizing($student));
    expect(Review::query()->count())->toBe(0)
        ->and($this->course->refresh()->rating_count)->toBe(0);
});
