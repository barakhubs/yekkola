<?php

declare(strict_types=1);

use App\Domain\Authoring\Actions\CreateCourse;
use App\Domain\Authoring\Actions\EditDraft;
use App\Domain\Authoring\Actions\SetLessonContent;
use App\Domain\Authoring\Models\Course;
use App\Domain\Catalog\Models\Category;
use App\Domain\Professors\Models\ProfessorProfile;
use Database\Seeders\CategorySeeder;
use Illuminate\Http\UploadedFile;

/*
| Course authoring helpers (PRD-03).
*/

/** Test-only RSA signing key (tests/Fixtures): [base64 private PEM, public PEM]. Not used anywhere else. */
function rsaKeyPair(): array
{
    $private = (string) file_get_contents(__DIR__.'/../Fixtures/mux-test-signing-key.pem');
    $key = openssl_pkey_get_private($private);

    return [base64_encode($private), openssl_pkey_get_details($key)['key']];
}

/**
 * A course whose draft passes the submit checklist: details, cover, category, price, one section with a
 * document, a quiz, and a processed video lesson. Requires Storage::fake('local') and ('public').
 */
function courseReadyForReview(ProfessorProfile $professor, string $title = 'Mathématiques pour l’Exetat'): Course
{
    test()->seed(CategorySeeder::class);
    $course = app(CreateCourse::class)->handle($professor, ['title' => $title]);
    $course->update([
        'category_id' => Category::query()->where('slug', 'mathematiques')->value('id'),
        'price_minor' => 1_500,
        'currency' => 'USD',
    ]);

    $edit = app(EditDraft::class);
    $content = app(SetLessonContent::class);
    $edit->updateDetails($course, [
        'description' => 'Préparation complète.',
        'outcomes' => ['Résoudre des équations', 'Étudier des fonctions', 'Réussir l’épreuve'],
    ]);
    $edit->setCover($course, UploadedFile::fake()->image('cover.jpg', 1280, 720));
    $section = $edit->addSection($course, 'Algèbre');

    $doc = $edit->addLesson($course, ['section_id' => $section->id, 'type' => 'document', 'title' => 'Fiche de révision', 'is_preview' => true]);
    $content->attachDocument($course, $doc->id, fakePdf('fiche.pdf'));

    $quiz = $edit->addLesson($course, ['section_id' => $section->id, 'type' => 'quiz', 'title' => 'Quiz 1']);
    $content->saveQuiz($course, $quiz->id, ['pass_mark_pct' => 60, 'questions' => [[
        'type' => 'single', 'prompt' => '2 + 2 ?', 'explanation' => 'Addition.',
        'options' => [['text' => '4', 'is_correct' => true], ['text' => '5', 'is_correct' => false]],
    ]]]);

    $video = $edit->addLesson($course, ['section_id' => $section->id, 'type' => 'video', 'title' => 'Les équations']);
    completeFakeVideo($course, $video->id);

    return $course->refresh();
}

/** Requests an upload for a video/audio lesson and completes it through the fake upload endpoint. */
function completeFakeVideo(Course $course, string $lessonId): void
{
    $result = app(SetLessonContent::class)->createMediaUpload($course, $lessonId, 'http://localhost:3000');

    test()->put('/api/v1/_fake/video-uploads/'.$result['asset']->provider_upload_id)->assertNoContent();
}
