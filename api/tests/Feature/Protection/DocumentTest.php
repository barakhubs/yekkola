<?php

declare(strict_types=1);

use App\Domain\Authoring\Models\Lesson;
use App\Domain\Identity\Models\User;
use App\Domain\Protection\Jobs\StampDocument;
use App\Domain\Protection\Models\DocumentStamp;
use App\Domain\Protection\Stamping\DocumentNotStampable;
use App\Domain\Protection\Stamping\DocumentStamper;
use App\Domain\Protection\Stamping\StampText;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use setasign\Fpdi\Fpdi;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->professor = actingAsProfessor();
    $this->course = publishedCourse($this->professor, 'Algèbre pour l’Exetat', ['is_free' => true, 'price_minor' => null, 'currency' => null]);
    $this->document = Lesson::query()->where('course_version_id', $this->course->live_version_id)->where('type', 'document')->sole();
    Lesson::query()->whereKey($this->document->id)->update(['is_preview' => false]);

    $this->student = User::factory()->create(['name' => 'Grâce Mbuyi', 'phone_e164' => '+243812345678']);
    Sanctum::actingAs($this->student);
    $this->postJson("/api/v1/courses/{$this->course->id}/enroll")->assertOk();
});

/** The PDF's content streams, inflated, so the stamp text can be searched. */
function pdfText(string $pdf): string
{
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);

    return implode("\n", array_map(fn (string $s) => @gzuncompress($s) ?: $s, $streams[1]));
}

it('gives each student a stamped copy behind a short-lived signed link', function () {
    $url = $this->getJson("/api/v1/lessons/{$this->document->uid}/document")->assertOk()
        ->assertJsonPath('data.status', 'ready')
        ->json('data.url');

    expect($url)->toContain('signature=');
    $download = $this->get($url)->assertOk();
    expect($download->headers->get('content-type'))->toBe('application/pdf')
        ->and($download->headers->get('cache-control'))->toContain('no-store');

    $pdf = $download->streamedContent();
    $text = pdfText($pdf);
    $stamp = DocumentStamp::query()->sole();
    expect($pdf)->toStartWith('%PDF')
        ->and($text)->toContain(iconv('UTF-8', 'Windows-1252', 'Grâce Mbuyi'))
        ->and($text)->toContain(iconv('UTF-8', 'Windows-1252', $this->student->maskedPhone()))
        ->and($text)->not->toContain('812345678');

    // The stamped copy keeps the original's pages.
    $file = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($file, $pdf);
    expect((new Fpdi)->setSourceFile($file))->toBe(1);

    // Asking again reuses the copy; a tampered or expired link is refused.
    $this->getJson("/api/v1/lessons/{$this->document->uid}/document")->assertOk();
    expect(DocumentStamp::query()->count())->toBe(1);
    $this->get($url.'x')->assertForbidden();
    $this->travel(11)->minutes();
    $this->get($url)->assertForbidden();
    expect($stamp->path)->toStartWith("stamped-documents/{$this->student->id}/");
});

it('refuses students who are not enrolled, and lessons that are not documents', function () {
    $quiz = Lesson::query()->where('course_version_id', $this->course->live_version_id)->where('type', 'quiz')->sole();
    $this->getJson("/api/v1/lessons/{$quiz->uid}/document")->assertUnprocessable()->assertJsonPath('code', 'document.not_a_document');

    Sanctum::actingAs(User::factory()->create());
    $this->getJson("/api/v1/lessons/{$this->document->uid}/document")->assertForbidden()->assertJsonPath('code', 'enrollment.required');

    // Preview documents: any signed-in user gets their own stamped copy.
    Lesson::query()->whereKey($this->document->id)->update(['is_preview' => true]);
    $this->getJson("/api/v1/lessons/{$this->document->uid}/document")->assertOk()->assertJsonPath('data.status', 'ready');
    expect(DocumentStamp::query()->count())->toBe(1);
});

it('refuses uploads the stamper cannot read, so no student ever gets an unstamped copy', function () {
    Sanctum::actingAs($this->professor->user);
    $this->postJson("/api/v1/professor/courses/{$this->course->id}/draft")->assertSuccessful();
    $draftDoc = Lesson::query()->where('course_version_id', $this->course->refresh()->draft_version_id)->where('type', 'document')->sole();

    $this->post("/api/v1/professor/courses/{$this->course->id}/draft/lessons/{$draftDoc->id}/document", ['document' => brokenPdf()], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'lesson.document_not_supported');

    $this->post("/api/v1/professor/courses/{$this->course->id}/draft/lessons/{$draftDoc->id}/document", ['document' => fakePdf('nouvelle-fiche.pdf')], ['Accept' => 'application/json'])
        ->assertOk();
});

it('keeps preview documents of unlisted courses private', function () {
    Lesson::query()->whereKey($this->document->id)->update(['is_preview' => true]);
    $this->course->update(['status' => 'unpublished']);

    Sanctum::actingAs(User::factory()->create());
    $this->getJson("/api/v1/lessons/{$this->document->uid}/document")->assertForbidden()->assertJsonPath('code', 'enrollment.required');

    // Enrolled students keep their access.
    Sanctum::actingAs($this->student);
    $this->getJson("/api/v1/lessons/{$this->document->uid}/document")->assertOk();
});

it('answers 202 while the copy is being made, and queues a lost copy again', function () {
    Queue::fake();

    $this->getJson("/api/v1/lessons/{$this->document->uid}/document")->assertStatus(202)
        ->assertJsonPath('data.status', 'processing')
        ->assertJsonPath('data.retry_after', 3);
    $this->getJson("/api/v1/lessons/{$this->document->uid}/document")->assertStatus(202);
    Queue::assertPushed(StampDocument::class, 1);

    $this->travel(16)->minutes(); // past the job's unique lock and the stale window
    $this->getJson("/api/v1/lessons/{$this->document->uid}/document")->assertStatus(202);
    Queue::assertPushed(StampDocument::class, 2);
});

it('marks an unreadable PDF as unavailable, and lets a transient failure start over', function () {
    app()->bind(DocumentStamper::class, fn () => new class implements DocumentStamper
    {
        public function inspect(string $sourcePath): int
        {
            return 1;
        }

        public function stamp(string $sourcePath, StampText $text): string
        {
            throw new DocumentNotStampable('damaged');
        }
    });

    $this->getJson("/api/v1/lessons/{$this->document->uid}/document")->assertConflict()->assertJsonPath('code', 'document.unavailable');
    expect(DocumentStamp::query()->sole()->status->value)->toBe('failed');

    // Out of retries on a storage error: the attempt is forgotten.
    DocumentStamp::query()->update(['status' => 'pending']);
    (new StampDocument(DocumentStamp::query()->sole()->id))->failed(new RuntimeException('S3 timeout'));
    expect(DocumentStamp::query()->count())->toBe(0);
});

it('stops signed links working for banned accounts, and erases copies with the account', function () {
    $url = $this->getJson("/api/v1/lessons/{$this->document->uid}/document")->json('data.url');
    $path = DocumentStamp::query()->sole()->path;
    expect(Storage::disk('local')->exists($path))->toBeTrue();

    $this->student->forceFill(['status' => 'banned'])->save();
    $this->get($url)->assertNotFound();

    event(new App\Domain\Identity\Events\UserAnonymizing($this->student));
    expect(DocumentStamp::query()->count())->toBe(0)
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('stops access when the enrollment is revoked', function () {
    App\Domain\Learning\Models\Enrollment::query()->update(['status' => 'revoked']);

    $this->getJson("/api/v1/lessons/{$this->document->uid}/document")->assertForbidden()->assertJsonPath('code', 'enrollment.required');
});
