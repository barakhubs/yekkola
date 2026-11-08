<?php

declare(strict_types=1);

use App\Domain\Authoring\Models\Lesson;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\LessonThread;
use App\Domain\Moderation\Models\Report;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->professor = actingAsProfessor('Marie Kabila');
    $this->course = publishedCourse($this->professor, 'Algèbre pour l’Exetat', ['is_free' => true, 'price_minor' => null, 'currency' => null]);
    $this->lesson = Lesson::query()->where('course_version_id', $this->course->live_version_id)->orderBy('position')->firstOrFail();

    $this->student = User::factory()->create(['name' => 'Jean Mbuyi']);
    actAs($this->student);
    $this->postJson("/api/v1/courses/{$this->course->id}/enroll")->assertOk();
});

function actAs(User $user): void
{
    app('auth')->forgetGuards();
    Sanctum::actingAs($user);
}

function ask(string $lessonUid, string $title = 'Comment factoriser ?'): Illuminate\Testing\TestResponse
{
    return test()->postJson("/api/v1/lessons/{$lessonUid}/threads", ['title' => $title, 'body' => 'Je ne comprends pas l’exemple 2.']);
}

it('lets enrolled students ask and the professor answer, marked and counted', function () {
    $id = ask($this->lesson->uid)->assertCreated()->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.author.name', 'Jean M.')->json('data.id');

    actAs($this->professor->user);
    $this->getJson('/api/v1/professor/threads')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.lesson_title', $this->lesson->title);
    $this->postJson("/api/v1/threads/{$id}/replies", ['body' => 'Regarde la leçon 3.'])->assertOk()
        ->assertJsonPath('data.status', 'answered')
        ->assertJsonPath('data.reply_count', 1)
        ->assertJsonPath('data.replies.0.author.is_professor', true);
    $this->getJson('/api/v1/professor/threads')->assertOk()->assertJsonCount(0, 'data');

    actAs($this->student);
    $this->postJson("/api/v1/threads/{$id}/replies", ['body' => 'Merci !'])->assertOk()
        ->assertJsonPath('data.reply_count', 2)
        ->assertJsonPath('data.replies.1.author.is_professor', false);
    $this->getJson("/api/v1/lessons/{$this->lesson->uid}/threads")->assertOk()->assertJsonCount(1, 'data');
});

it('keeps Q&A to participants, hides moderated and banned posts from others', function () {
    $id = ask($this->lesson->uid)->assertCreated()->json('data.id');

    $outsider = User::factory()->create();
    actAs($outsider);
    ask($this->lesson->uid)->assertForbidden()->assertJsonPath('code', 'qa.access_required');
    $this->getJson("/api/v1/threads/{$id}")->assertForbidden();
    $this->postJson('/api/v1/reports', ['type' => 'thread', 'id' => $id, 'reason' => 'spam'])->assertNotFound();

    $classmate = User::factory()->create();
    actAs($classmate);
    $this->postJson("/api/v1/courses/{$this->course->id}/enroll")->assertOk();
    $this->postJson('/api/v1/reports', ['type' => 'thread', 'id' => $id, 'reason' => 'spam'])->assertCreated();

    $moderator = actingAsStaff(Role::Moderator);
    $report = Report::query()->sole();
    $this->getJson('/api/v1/admin/reports')->assertOk()->assertJsonPath('data.0.can.hide', true)
        ->assertJsonPath('data.0.target.title', 'Comment factoriser ?');
    $this->postJson("/api/v1/admin/reports/{$report->id}/resolve", ['action' => 'hide', 'note' => 'Hors sujet'])->assertOk();
    expect(LessonThread::query()->sole()->hidden_at)->not->toBeNull();

    actAs($classmate);
    $this->getJson("/api/v1/lessons/{$this->lesson->uid}/threads")->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/threads/{$id}")->assertNotFound();
    actAs($this->student); // its author still sees it, marked hidden, and can't get replies on it
    $this->getJson("/api/v1/lessons/{$this->lesson->uid}/threads")->assertOk()->assertJsonPath('data.0.hidden', true);
    $this->postJson("/api/v1/threads/{$id}/replies", ['body' => 'Et alors ?'])->assertConflict()->assertJsonPath('code', 'qa.thread_hidden');

    actAs($moderator);
    $this->postJson("/api/v1/admin/threads/{$id}/restore", ['note' => 'Erreur'])->assertOk()->assertJsonPath('data.can.hide', true);
    $this->student->forceFill(['status' => UserStatus::Banned])->save();
    actAs($classmate);
    $this->getJson("/api/v1/lessons/{$this->lesson->uid}/threads")->assertOk()->assertJsonCount(0, 'data');
});

it('lets moderators hide one reply, for staff who handle reports only', function () {
    $id = ask($this->lesson->uid)->assertCreated()->json('data.id');
    $replyId = $this->postJson("/api/v1/threads/{$id}/replies", ['body' => 'Lien vers un site douteux'])->assertOk()->json('data.replies.0.id');

    actingAsStaff(Role::Moderator);
    $this->postJson("/api/v1/admin/thread-replies/{$replyId}/hide", [])->assertUnprocessable();
    $this->postJson("/api/v1/admin/thread-replies/{$replyId}/hide", ['note' => 'Spam'])->assertOk()
        ->assertJsonPath('data.replies.0.can.restore', true);
    $this->postJson("/api/v1/admin/thread-replies/{$replyId}/hide", ['note' => 'Encore'])->assertConflict();
    $this->getJson('/api/v1/admin/threads?filter[hidden]=true')->assertOk()->assertJsonCount(1, 'data');

    actAs($this->professor->user);
    $this->getJson("/api/v1/threads/{$id}")->assertOk()->assertJsonCount(0, 'data.replies');
    $this->getJson('/api/v1/admin/threads')->assertForbidden();
});

it('re-opens a question whose only professor answer is hidden, and answers it again on restore', function () {
    $id = ask($this->lesson->uid)->assertCreated()->json('data.id');
    actAs($this->professor->user);
    $replyId = $this->postJson("/api/v1/threads/{$id}/replies", ['body' => 'Hors sujet.'])->assertOk()->json('data.replies.0.id');
    $before = LessonThread::query()->sole()->updated_at;

    $moderator = actingAsStaff(Role::Moderator);
    $this->postJson("/api/v1/admin/thread-replies/{$replyId}/hide", ['note' => 'Hors sujet'])->assertOk()->assertJsonPath('data.status', 'open');
    expect(LessonThread::query()->sole()->updated_at->equalTo($before))->toBeTrue();

    actAs($this->student);
    $this->getJson("/api/v1/lessons/{$this->lesson->uid}/threads")->assertOk()->assertJsonPath('data.0.reply_count', 0);

    actAs($moderator);
    $this->postJson("/api/v1/admin/thread-replies/{$replyId}/restore", ['note' => 'Erreur'])->assertOk()->assertJsonPath('data.status', 'answered');
});
