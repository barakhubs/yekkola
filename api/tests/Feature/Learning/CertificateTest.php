<?php

declare(strict_types=1);

use App\Domain\Authoring\Models\Lesson;
use App\Domain\Authoring\Models\Quiz;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Actions\RevokeEnrollments;
use App\Domain\Learning\Events\CertificateIssued;
use App\Domain\Learning\Models\Certificate;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Platform\Settings\LearningSettings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->professor = actingAsProfessor('Marie Kabila');
    // A document, a quiz (2 + 2 = 4), and a 20-minute video.
    $this->course = publishedCourse($this->professor, 'Algèbre pour l’Exetat', ['is_free' => true, 'price_minor' => null, 'currency' => null]);
    $lessons = Lesson::query()->where('course_version_id', $this->course->live_version_id)->orderBy('position')->get();
    [$this->document, $this->quiz, $this->video] = [$lessons[0], $lessons[1], $lessons[2]];

    $this->student = User::factory()->create(['name' => 'Grâce Mbuyi', 'locale' => 'fr']);
    Sanctum::actingAs($this->student);
    $this->postJson("/api/v1/courses/{$this->course->id}/enroll")->assertOk();
});

function finishLessons(array $lessons): void
{
    test()->putJson('/api/v1/me/progress', ['items' => array_map(fn (Lesson $l) => $l->type->value === 'video'
        ? ['lesson_uid' => $l->uid, 'position_seconds' => 1_200]
        : ['lesson_uid' => $l->uid, 'completed' => true], $lessons)])->assertOk();
}

function passQuiz(Lesson $lesson, string $answer = '4'): void
{
    $question = Quiz::query()->where('lesson_id', $lesson->id)->with('questions.options')->sole()->questions->first();
    $id = test()->postJson("/api/v1/lessons/{$lesson->uid}/quiz-attempts")->json('data.id');
    test()->putJson("/api/v1/quiz-attempts/{$id}", ['answers' => [$question->id => [$question->options->firstWhere('text', $answer)->id]]])->assertOk();
}

it('issues one certificate when the course is complete, with snapshotted names', function () {
    Event::fake([CertificateIssued::class]);
    finishLessons([$this->document, $this->video]);
    expect(Certificate::query()->count())->toBe(0); // the quiz is still to pass

    passQuiz($this->quiz);
    passQuiz($this->quiz); // another pass changes nothing
    finishLessons([$this->document]);

    $certificate = Certificate::query()->sole();
    expect($certificate->serial)->toMatch('/^YK-[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4}$/')
        ->and($certificate->student_name_snapshot)->toBe('Grâce Mbuyi')
        ->and($certificate->professor_name_snapshot)->toBe('Marie Kabila')
        ->and($certificate->course_title_snapshot)->toBe('Algèbre pour l’Exetat');
    Event::assertDispatchedTimes(CertificateIssued::class, 1);

    // Later renames don't change it.
    $this->student->update(['name' => 'Grâce M.']);
    $this->getJson('/api/v1/me/certificates')->assertOk()
        ->assertJsonPath('data.0.serial', $certificate->serial)
        ->assertJsonPath('data.0.student_name', 'Grâce Mbuyi')
        ->assertJsonPath('data.0.verification_url', 'http://localhost:3000/certificates/'.$certificate->serial);
    $this->getJson("/api/v1/me/courses/{$this->course->id}")->assertJsonPath('data.certificate.serial', $certificate->serial);
});

it('follows the admin completion rule', function () {
    $settings = app(LearningSettings::class);
    $settings->certificate_min_completion_pct = 60;
    $settings->certificate_requires_quizzes_passed = false;
    $settings->save();

    finishLessons([$this->document, $this->video]); // 66 %, quiz not passed
    expect(Certificate::query()->count())->toBe(1);
});

it('waits for the student to add a name', function () {
    $this->student->forceFill(['name' => null])->save();
    finishLessons([$this->document, $this->video]);
    passQuiz($this->quiz);
    expect(Certificate::query()->count())->toBe(0);

    Sanctum::actingAs($this->student->fresh());
    $this->patchJson('/api/v1/me', ['name' => 'Grâce Mbuyi'])->assertOk();
    expect(Certificate::query()->sole()->student_name_snapshot)->toBe('Grâce Mbuyi');
});

it('verifies a certificate publicly and shows it revoked after the enrollment is revoked', function () {
    finishLessons([$this->document, $this->video]);
    passQuiz($this->quiz);
    $certificate = Certificate::query()->sole();

    Sanctum::actingAs(User::factory()->create()); // anyone can verify
    $this->getJson('/api/v1/certificates/'.strtolower($certificate->serial))->assertOk()
        ->assertJsonPath('data.status', 'valid')
        ->assertJsonPath('data.student_name', 'Grâce Mbuyi')
        ->assertJsonPath('data.course_slug', $this->course->slug);
    $this->getJson('/api/v1/certificates/YK-0000-0000-0000')->assertNotFound();

    // Refunds and reversals revoke through RevokeEnrollments (by order item).
    $enrollment = Enrollment::query()->sole();
    Illuminate\Support\Facades\DB::transaction(function () use ($enrollment) {
        $itemId = paidOrderItemFor($enrollment);
        expect(app(RevokeEnrollments::class)->handle([$itemId], 'refunded'))->toBe(1);
    });

    $this->getJson("/api/v1/certificates/{$certificate->serial}")->assertOk()
        ->assertJsonPath('data.status', 'revoked')
        ->assertJsonPath('data.revoked_at', fn ($v) => $v !== null);

    // A new grant and completion earns a new certificate; the old one stays revoked.
    app(App\Domain\Learning\Actions\GrantEnrollment::class)->handle($this->student->id, $this->course->id, App\Domain\Learning\Enums\EnrollmentSource::Admin);
    expect(Certificate::query()->valid()->sole()->serial)->not->toBe($certificate->serial)
        ->and(Certificate::query()->count())->toBe(2);
});

it('gives my certificate as a PDF in my language, and only mine', function () {
    finishLessons([$this->document, $this->video]);
    passQuiz($this->quiz);
    $certificate = Certificate::query()->sole();

    $pdf = $this->get("/api/v1/me/certificates/{$certificate->id}/pdf", ['Accept-Language' => 'en'])->assertOk();
    expect($pdf->headers->get('content-type'))->toBe('application/pdf')
        ->and($pdf->headers->get('content-disposition'))->toContain('certificat-'.$certificate->serial.'.pdf'); // the student's language (fr)

    Sanctum::actingAs(User::factory()->create());
    $this->get("/api/v1/me/certificates/{$certificate->id}/pdf")->assertNotFound();
});

it('erases certificates when the account is deleted', function () {
    finishLessons([$this->document, $this->video]);
    passQuiz($this->quiz);

    event(new App\Domain\Identity\Events\UserAnonymizing($this->student));
    expect(Certificate::query()->count())->toBe(0);
});
