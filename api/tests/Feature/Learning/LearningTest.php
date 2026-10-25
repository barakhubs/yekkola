<?php

declare(strict_types=1);

use App\Domain\Authoring\Models\Lesson;
use App\Domain\Authoring\Models\Quiz;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Events\CourseCompleted;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Learning\Models\LessonProgress;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->professor = actingAsProfessor();
    // A document (preview), a quiz (2 + 2 = 4), and a 20-minute video.
    $this->course = publishedCourse($this->professor, 'Algèbre pour l’Exetat', ['is_free' => true, 'price_minor' => null, 'currency' => null]);
    $lessons = Lesson::query()->where('course_version_id', $this->course->live_version_id)->orderBy('position')->get();
    [$this->document, $this->quiz, $this->video] = [$lessons[0], $lessons[1], $lessons[2]];

    $this->student = User::factory()->create();
    Sanctum::actingAs($this->student);
    $this->postJson("/api/v1/courses/{$this->course->id}/enroll")->assertOk();
});

function syncProgress(array $items): Illuminate\Testing\TestResponse
{
    return test()->putJson('/api/v1/me/progress', ['items' => $items]);
}

function quizAnswer(string $optionText): array
{
    $quiz = Quiz::query()->where('lesson_id', test()->quiz->id)->with('questions.options')->sole();
    $question = $quiz->questions->firstWhere('prompt', '2 + 2 ?');

    return [$question->id => [$question->options->firstWhere('text', $optionText)->id]];
}

it('lists my courses and shows the learning view without answers', function () {
    $this->getJson('/api/v1/me/enrollments')->assertOk()
        ->assertJsonPath('data.0.course.slug', $this->course->slug)
        ->assertJsonPath('data.0.progress_pct', 0);

    $view = $this->getJson("/api/v1/me/courses/{$this->course->id}")->assertOk()
        ->assertJsonPath('data.next_lesson_uid', $this->document->uid)
        ->assertJsonPath('data.sections.0.lessons.0.status', 'not_started')
        ->assertJsonPath('data.sections.0.lessons.1.quiz.question_count', 1)
        ->assertJsonPath('data.sections.0.lessons.1.quiz.attempts_used', 0);
    expect(json_encode($view->json()))->not->toContain('is_correct');

    Sanctum::actingAs(User::factory()->create());
    $this->getJson("/api/v1/me/courses/{$this->course->id}")->assertForbidden()->assertJsonPath('code', 'enrollment.required');
});

it('merges progress monotonically: furthest position wins, completion never regresses', function () {
    syncProgress([['lesson_uid' => $this->video->uid, 'position_seconds' => 600, 'client_updated_at' => '2026-10-20T10:00:00Z']])
        ->assertOk()->assertJsonPath('data.accepted', [$this->video->uid]);
    expect(LessonProgress::query()->sole()->status->value)->toBe('in_progress');

    syncProgress([['lesson_uid' => $this->video->uid, 'position_seconds' => 1_100]]); // ≥ 90% of 1,200 s
    syncProgress([['lesson_uid' => $this->video->uid, 'position_seconds' => 300]]);   // an older device syncing late

    $progress = LessonProgress::query()->sole();
    expect($progress->status->value)->toBe('completed')
        ->and($progress->position_seconds)->toBe(1_100);

    syncProgress([
        ['lesson_uid' => $this->document->uid, 'completed' => true],
        ['lesson_uid' => $this->quiz->uid, 'completed' => true], // a quiz only completes by passing it
        ['lesson_uid' => '01JZZZZZZZZZZZZZZZZZZZZZZZ', 'position_seconds' => 10],
    ])->assertJsonPath('data.skipped', ['01JZZZZZZZZZZZZZZZZZZZZZZZ'])
        ->assertJsonPath("data.courses.{$this->course->id}", 66);

    $this->getJson('/api/v1/me/enrollments')->assertJsonPath('data.0.progress_pct', 66);
});

it('grades quizzes against the key, explains answers, and completes the course on a pass', function () {
    Event::fake([CourseCompleted::class]);
    syncProgress([['lesson_uid' => $this->document->uid, 'completed' => true], ['lesson_uid' => $this->video->uid, 'position_seconds' => 1_200]]);

    $attempt = $this->postJson("/api/v1/lessons/{$this->quiz->uid}/quiz-attempts")->assertCreated()->json('data');
    expect($attempt['questions'][0]['correct'])->toBeNull()
        ->and($attempt['questions'][0]['explanation'])->toBeNull()
        ->and($attempt['questions'][0]['chosen'])->toBeNull()
        ->and(json_encode($attempt))->not->toContain('is_correct');
    $this->postJson("/api/v1/lessons/{$this->quiz->uid}/quiz-attempts")->assertOk()->assertJsonPath('data.id', $attempt['id']); // resumed

    $this->putJson("/api/v1/quiz-attempts/{$attempt['id']}", ['answers' => quizAnswer('5')])->assertOk()
        ->assertJsonPath('data.passed', false)
        ->assertJsonPath('data.score_pct', 0)
        ->assertJsonPath('data.questions.0.explanation', 'Addition.');
    Event::assertNotDispatched(CourseCompleted::class);

    $second = $this->postJson("/api/v1/lessons/{$this->quiz->uid}/quiz-attempts")->assertCreated()->json('data.id');
    $this->putJson("/api/v1/quiz-attempts/{$second}", ['answers' => quizAnswer('4')])->assertOk()->assertJsonPath('data.passed', true);
    $this->putJson("/api/v1/quiz-attempts/{$second}", ['answers' => quizAnswer('4')])->assertOk(); // replay
    $this->putJson("/api/v1/quiz-attempts/{$second}", ['answers' => quizAnswer('5')])->assertOk()->assertJsonPath('data.passed', true); // stored result

    expect(Enrollment::query()->sole()->progress_pct)->toBe(100)
        ->and(Enrollment::query()->sole()->completed_at)->not->toBeNull();
    Event::assertDispatchedTimes(CourseCompleted::class, 1);
});

it('enforces the attempt limit and the time limit on the server', function () {
    Quiz::query()->where('lesson_id', $this->quiz->id)->update(['max_attempts' => 1, 'time_limit_seconds' => 60]);

    $attempt = $this->postJson("/api/v1/lessons/{$this->quiz->uid}/quiz-attempts")->assertCreated()->json('data');
    expect($attempt['ends_at'])->not->toBeNull();

    $this->travel(2)->minutes();
    $this->putJson("/api/v1/quiz-attempts/{$attempt['id']}", ['answers' => quizAnswer('4')])->assertOk()
        ->assertJsonPath('data.timed_out', true)
        ->assertJsonPath('data.passed', false);

    $this->postJson("/api/v1/lessons/{$this->quiz->uid}/quiz-attempts")->assertConflict()->assertJsonPath('code', 'quiz.max_attempts_reached');
});

it('lets anyone signed in try a preview quiz, but nothing else without enrolling', function () {
    Lesson::query()->whereKey($this->quiz->id)->update(['is_preview' => true]);
    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/v1/lessons/{$this->quiz->uid}/quiz-attempts")->assertCreated();
    $this->postJson("/api/v1/lessons/{$this->video->uid}/quiz-attempts")->assertForbidden();
    syncProgress([['lesson_uid' => $this->video->uid, 'position_seconds' => 100]])->assertJsonPath('data.skipped', [$this->video->uid]);
});

it('returns the stored result when a submission is retried, even on a shuffled quiz', function () {
    $quiz = Quiz::query()->where('lesson_id', $this->quiz->id)->sole();
    $quiz->update(['shuffle_questions' => true]);
    $second = $quiz->questions()->create(['type' => 'true_false', 'prompt' => 'Kinshasa est la capitale ?', 'points' => 1, 'position' => 2]);
    $true = $second->options()->create(['text' => 'Vrai', 'is_correct' => true, 'position' => 1]);
    $second->options()->create(['text' => 'Faux', 'is_correct' => false, 'position' => 2]);

    $id = $this->postJson("/api/v1/lessons/{$this->quiz->uid}/quiz-attempts")->json('data.id');
    $answers = [...quizAnswer('4'), $second->id => [$true->id]];

    $first = $this->putJson("/api/v1/quiz-attempts/{$id}", ['answers' => $answers])->assertOk()->assertJsonPath('data.score_pct', 100)->json('data');
    $this->putJson("/api/v1/quiz-attempts/{$id}", ['answers' => array_reverse($answers, true)])->assertOk()->assertJsonPath('data.score_pct', $first['score_pct']);
});

it('grades a submission arriving in the grace period after the time limit', function () {
    Quiz::query()->where('lesson_id', $this->quiz->id)->update(['time_limit_seconds' => 60]);
    $attempt = $this->postJson("/api/v1/lessons/{$this->quiz->uid}/quiz-attempts")->json('data');
    expect($attempt)->not->toHaveKey('deadline_at')->and($attempt['ends_at'])->not->toBeNull();

    $this->travel(75)->seconds(); // past the time limit, inside the grace
    $this->putJson("/api/v1/quiz-attempts/{$attempt['id']}", ['answers' => quizAnswer('4')])
        ->assertJsonPath('data.timed_out', false)
        ->assertJsonPath('data.passed', true);
});

it('ends an attempt when access is revoked, and keeps attempts private', function () {
    $id = $this->postJson("/api/v1/lessons/{$this->quiz->uid}/quiz-attempts")->json('data.id');

    Sanctum::actingAs(User::factory()->create());
    $this->putJson("/api/v1/quiz-attempts/{$id}", ['answers' => quizAnswer('4')])->assertNotFound();

    Sanctum::actingAs($this->student);
    Enrollment::query()->update(['status' => 'revoked']);
    $this->putJson("/api/v1/quiz-attempts/{$id}", ['answers' => quizAnswer('4')])->assertForbidden();
});

it('completes the course again after a new grant, and recomputes progress when a version goes live', function () {
    Event::fake([CourseCompleted::class]);
    syncProgress([['lesson_uid' => $this->document->uid, 'completed' => true], ['lesson_uid' => $this->video->uid, 'position_seconds' => 1_200]]);
    expect(Enrollment::query()->sole()->progress_pct)->toBe(66);

    // A new version without the quiz goes live: everything left is done.
    $draft = app(App\Domain\Authoring\Actions\StartDraftFromLive::class)->handle($this->course);
    Lesson::query()->where('course_version_id', $draft->id)->where('uid', $this->quiz->uid)->delete();
    $draft->forceFill(['state' => App\Domain\Authoring\Enums\VersionState::Approved])->save();
    $this->course->refresh()->forceFill(['live_version_id' => $draft->id, 'draft_version_id' => null])->save();
    event(new App\Domain\Authoring\Events\CourseVersionDecided($draft->refresh()));

    expect(Enrollment::query()->sole()->progress_pct)->toBe(100);
    Event::assertDispatchedTimes(CourseCompleted::class, 1);

    // Revoked, then granted again: a new completion.
    Enrollment::query()->update(['status' => 'revoked']);
    app(App\Domain\Learning\Actions\GrantEnrollment::class)->handle($this->student->id, $this->course->id, App\Domain\Learning\Enums\EnrollmentSource::Admin);
    Event::assertDispatchedTimes(CourseCompleted::class, 2);
});

it('lets a student resume an open attempt on the last allowed try, and grades each question for the results', function () {
    Quiz::query()->where('lesson_id', $this->quiz->id)->update(['max_attempts' => 1, 'time_limit_seconds' => 600]);
    $quizFacts = fn () => $this->getJson("/api/v1/me/courses/{$this->course->id}")->json('data.sections.0.lessons.1.quiz');

    expect($quizFacts())->toMatchArray(['can_start' => true, 'open_attempt_id' => null]);

    $attempt = $this->postJson("/api/v1/lessons/{$this->quiz->uid}/quiz-attempts")->assertCreated()->json('data');
    expect($attempt['seconds_left'])->toBeGreaterThan(590)->toBeLessThanOrEqual(600)
        ->and($quizFacts())->toMatchArray(['attempts_used' => 1, 'can_start' => true, 'open_attempt_id' => $attempt['id']]);

    $result = $this->putJson("/api/v1/quiz-attempts/{$attempt['id']}", ['answers' => quizAnswer('5')])->assertOk()->json('data');
    expect($result['questions'][0]['answered_correctly'])->toBeFalse()
        ->and($result['seconds_left'])->toBeNull()
        ->and($quizFacts())->toMatchArray(['can_start' => false, 'open_attempt_id' => null]);
});

it('refuses answers that are not lists of option ids', function () {
    $id = $this->postJson("/api/v1/lessons/{$this->quiz->uid}/quiz-attempts")->json('data.id');

    $this->putJson("/api/v1/quiz-attempts/{$id}", ['answers' => ['q' => 'not-a-list']])->assertUnprocessable()->assertJsonValidationErrors('answers');
    $this->putJson("/api/v1/quiz-attempts/{$id}", ['answers' => ['q' => ['short']]])->assertUnprocessable()->assertJsonValidationErrors('answers');
});
