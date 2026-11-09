<?php

declare(strict_types=1);

use App\Domain\Authoring\Enums\CourseStatus;
use App\Domain\Authoring\Enums\MediaAssetStatus;
use App\Domain\Authoring\Enums\VersionState;
use App\Domain\Authoring\Models\Course;
use App\Domain\Authoring\Models\Lesson;
use App\Domain\Authoring\Models\MediaAsset;
use App\Domain\Platform\Settings\CatalogSettings;
use App\Integrations\Video\VideoProvider;
use Database\Seeders\CategorySeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->seed(CategorySeeder::class);
    $this->professor = actingAsProfessor();
});

function newCourse(string $title = 'Physique pour l’Exetat'): array
{
    return test()->postJson('/api/v1/professor/courses', ['title' => $title])->assertCreated()->json('data');
}

it('creates a course with a first draft version', function () {
    $course = newCourse();

    expect($course['status'])->toBe('draft')
        ->and($course['slug'])->toBe('physique-pour-lexetat')
        ->and($course['draft_version']['number'])->toBe(1)
        ->and($course['draft_version']['state'])->toBe('draft')
        ->and($course['live_version'])->toBeNull();
});

it("keeps professors out of each other's courses", function () {
    $course = newCourse();
    actingAsProfessor('Autre Professeur');

    $this->getJson("/api/v1/professor/courses/{$course['id']}")->assertNotFound();
    $this->getJson("/api/v1/professor/courses/{$course['id']}/draft")->assertNotFound();
});

it('validates prices against the platform settings', function (array $payload, int $status) {
    $course = newCourse();

    $this->patchJson("/api/v1/professor/courses/{$course['id']}", $payload)->assertStatus($status);
})->with([
    'valid USD' => [['price_minor' => 1_500, 'currency' => 'USD'], 200],
    'below minimum' => [['price_minor' => 50, 'currency' => 'USD'], 422],
    'above maximum' => [['price_minor' => 60_000, 'currency' => 'USD'], 422],
    'CDF centimes' => [['price_minor' => 500_050, 'currency' => 'CDF'], 422],
    'valid CDF' => [['price_minor' => 500_000, 'currency' => 'CDF'], 200],
    'unknown currency' => [['price_minor' => 1_500, 'currency' => 'EUR'], 422],
]);

it('states price limits as money, not minor units', function () {
    $course = newCourse();

    $message = $this->patchJson("/api/v1/professor/courses/{$course['id']}", ['price_minor' => 50, 'currency' => 'USD'], ['Accept-Language' => 'en'])
        ->assertUnprocessable()
        ->json('errors.price_minor.0');

    expect($message)->toContain('$1.00')->and($message)->not->toContain('100 ');
});

it('makes a course free, clearing its price, within the free-course limit', function () {
    $first = newCourse();
    $this->patchJson("/api/v1/professor/courses/{$first['id']}", ['price_minor' => 1_500, 'currency' => 'USD']);

    $this->patchJson("/api/v1/professor/courses/{$first['id']}", ['is_free' => true])
        ->assertOk()
        ->assertJsonPath('data.is_free', true)
        ->assertJsonPath('data.price', null);

    $settings = app(CatalogSettings::class);
    $settings->free_course_limit = 1;
    $settings->save();

    $second = newCourse('Chimie');
    $this->patchJson("/api/v1/professor/courses/{$second['id']}", ['is_free' => true])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'course.free_limit_reached');
});

it("counts the course's own media against the free-hours limit", function () {
    $course = courseReadyForReview($this->professor); // 20 minutes of video

    $settings = app(CatalogSettings::class);
    $settings->free_media_hours_limit = 0;
    $settings->save();

    $this->patchJson("/api/v1/professor/courses/{$course->id}", ['is_free' => true])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'course.free_hours_limit_reached');
});

it("keeps professors out of each other's sections, lessons and media", function () {
    $mine = newCourse();
    $section = $this->postJson("/api/v1/professor/courses/{$mine['id']}/draft/sections", ['title' => 'Section A'])->json('data.id');
    $lesson = $this->postJson("/api/v1/professor/courses/{$mine['id']}/draft/lessons", ['section_id' => $section, 'type' => 'video', 'title' => 'Vidéo'])->json('data.id');
    $media = $this->postJson("/api/v1/professor/courses/{$mine['id']}/draft/lessons/{$lesson}/media", ['size_bytes' => 1_000, 'content_type' => 'video/mp4'])->json('data.media.id');

    actingAsProfessor('Autre Professeur');
    $theirs = newCourse('Chimie');
    $theirSection = $this->postJson("/api/v1/professor/courses/{$theirs['id']}/draft/sections", ['title' => 'Section B'])->json('data.id');

    $this->patchJson("/api/v1/professor/courses/{$mine['id']}/draft/sections/{$section}", ['title' => 'Piraté'])->assertNotFound();
    $this->patchJson("/api/v1/professor/courses/{$theirs['id']}/draft/sections/{$section}", ['title' => 'Piraté'])->assertNotFound();
    $this->deleteJson("/api/v1/professor/courses/{$theirs['id']}/draft/lessons/{$lesson}")->assertNotFound();
    $this->postJson("/api/v1/professor/courses/{$theirs['id']}/draft/lessons", ['section_id' => $section, 'type' => 'quiz', 'title' => 'Intrus'])->assertNotFound();
    $this->getJson("/api/v1/professor/media/{$media}")->assertNotFound();
    $this->postJson("/api/v1/professor/media/{$media}/parts", ['parts' => [1]])->assertNotFound();
    $this->postJson("/api/v1/professor/media/{$media}/complete", ['parts' => [['number' => 1, 'etag' => '"fake-1"']]])->assertNotFound();
    $this->putJson("/api/v1/professor/courses/{$theirs['id']}/draft/order", ['sections' => [
        ['id' => $theirSection, 'lessons' => [$lesson]],
    ]])->assertUnprocessable();
});

it('edits draft details and requires a 16:9 cover of at least 1280x720', function () {
    $course = newCourse();

    $this->patchJson("/api/v1/professor/courses/{$course['id']}/draft", [
        'subtitle' => 'Tout le programme',
        'outcomes' => ['Comprendre la mécanique'],
    ])->assertOk()->assertJsonPath('data.subtitle', 'Tout le programme');

    $this->post("/api/v1/professor/courses/{$course['id']}/draft/cover", ['cover' => UploadedFile::fake()->image('c.jpg', 800, 600)], ['Accept' => 'application/json'])
        ->assertUnprocessable();
    $this->post("/api/v1/professor/courses/{$course['id']}/draft/cover", ['cover' => UploadedFile::fake()->image('c.jpg', 1920, 1080)], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.cover_url', fn ($url) => is_string($url));
});

it('builds the curriculum and enforces the preview limit', function () {
    $course = newCourse();
    $section = $this->postJson("/api/v1/professor/courses/{$course['id']}/draft/sections", ['title' => 'Mécanique'])->assertCreated()->json('data');

    foreach (range(1, 3) as $i) {
        $this->postJson("/api/v1/professor/courses/{$course['id']}/draft/lessons", [
            'section_id' => $section['id'], 'type' => 'audio', 'title' => "Leçon {$i}", 'is_preview' => true,
        ])->assertCreated();
    }

    $this->postJson("/api/v1/professor/courses/{$course['id']}/draft/lessons", [
        'section_id' => $section['id'], 'type' => 'audio', 'title' => 'Leçon 4', 'is_preview' => true,
    ])->assertUnprocessable()->assertJsonPath('code', 'lesson.preview_limit_reached');

    $draft = $this->getJson("/api/v1/professor/courses/{$course['id']}/draft")->assertOk();
    expect($draft->json('data.sections.0.lessons'))->toHaveCount(3)
        ->and($draft->json('data.checklist.ready'))->toBeFalse();
});

it('reorders sections and lessons, moving lessons across sections', function () {
    $course = newCourse();
    $a = $this->postJson("/api/v1/professor/courses/{$course['id']}/draft/sections", ['title' => 'Section A'])->json('data.id');
    $b = $this->postJson("/api/v1/professor/courses/{$course['id']}/draft/sections", ['title' => 'Section B'])->json('data.id');
    $l1 = $this->postJson("/api/v1/professor/courses/{$course['id']}/draft/lessons", ['section_id' => $a, 'type' => 'quiz', 'title' => 'L1'])->json('data.id');
    $l2 = $this->postJson("/api/v1/professor/courses/{$course['id']}/draft/lessons", ['section_id' => $a, 'type' => 'quiz', 'title' => 'L2'])->json('data.id');

    $this->putJson("/api/v1/professor/courses/{$course['id']}/draft/order", ['sections' => [
        ['id' => $b, 'lessons' => [$l2]],
        ['id' => $a, 'lessons' => [$l1]],
    ]])->assertOk()
        ->assertJsonPath('data.sections.0.title', 'Section B')
        ->assertJsonPath('data.sections.0.lessons.0.title', 'L2');

    $this->putJson("/api/v1/professor/courses/{$course['id']}/draft/order", ['sections' => [
        ['id' => $a, 'lessons' => [$l1]],
    ]])->assertUnprocessable()->assertJsonPath('code', 'course.invalid_order');
});

it('saves quizzes with validated answers and keeps the answer key in the studio', function () {
    $course = newCourse();
    $section = $this->postJson("/api/v1/professor/courses/{$course['id']}/draft/sections", ['title' => 'Section 1'])->json('data.id');
    $lesson = $this->postJson("/api/v1/professor/courses/{$course['id']}/draft/lessons", ['section_id' => $section, 'type' => 'quiz', 'title' => 'Quiz'])->json('data.id');

    $bad = ['pass_mark_pct' => 60, 'questions' => [['type' => 'single', 'prompt' => 'Choisir', 'options' => [
        ['text' => 'A', 'is_correct' => true], ['text' => 'B', 'is_correct' => true],
    ]]]];
    $this->putJson("/api/v1/professor/courses/{$course['id']}/draft/lessons/{$lesson}/quiz", $bad)
        ->assertUnprocessable()
        ->assertJsonStructure(['errors' => ['questions.0.options']]);

    $good = ['pass_mark_pct' => 60, 'time_limit_seconds' => 600, 'questions' => [
        ['type' => 'true_false', 'prompt' => 'La Terre est ronde.', 'explanation' => 'Oui.', 'options' => [['text' => 'Vrai', 'is_correct' => true], ['text' => 'Faux', 'is_correct' => false]]],
        ['type' => 'multiple', 'prompt' => 'Nombres pairs', 'options' => [['text' => '2', 'is_correct' => true], ['text' => '3', 'is_correct' => false], ['text' => '4', 'is_correct' => true]]],
    ]];
    $this->putJson("/api/v1/professor/courses/{$course['id']}/draft/lessons/{$lesson}/quiz", $good)
        ->assertOk()
        ->assertJsonPath('data.quiz.questions.1.options.2.is_correct', true)
        ->assertJsonPath('data.is_content_ready', true);
});

it('uploads video in parts straight to storage, then processes it once complete', function () {
    $course = newCourse();
    $section = $this->postJson("/api/v1/professor/courses/{$course['id']}/draft/sections", ['title' => 'Section 1'])->json('data.id');
    $lesson = $this->postJson("/api/v1/professor/courses/{$course['id']}/draft/lessons", ['section_id' => $section, 'type' => 'video', 'title' => 'Vidéo'])->json('data.id');

    $start = fn (int $size, string $type = 'video/mp4') => $this->postJson("/api/v1/professor/courses/{$course['id']}/draft/lessons/{$lesson}/media", ['size_bytes' => $size, 'content_type' => $type]);
    $start(app(CatalogSettings::class)->max_video_upload_mb * 1024 * 1024 + 1)->assertUnprocessable()->assertJsonPath('code', 'media.upload_too_large');
    $start(1_000, 'image/png')->assertUnprocessable()->assertJsonPath('code', 'lesson.type_mismatch');

    // 25 MB in 10 MB parts: three parts, all URLs in the first batch.
    $upload = $start(25 * 1024 * 1024)->assertCreated()->json('data');
    $media = $upload['media']['id'];
    expect($upload['media']['status'])->toBe('waiting_upload')
        ->and($upload['upload']['part_count'])->toBe(3)
        ->and(array_column($upload['upload']['parts'], 'number'))->toBe([1, 2, 3]);

    $parts = [];
    foreach ($upload['upload']['parts'] as $part) {
        $parts[] = ['number' => $part['number'], 'etag' => $this->put(parse_url($part['url'], PHP_URL_PATH))->assertOk()->headers->get('ETag')];
    }
    $this->postJson("/api/v1/professor/media/{$media}/parts", ['parts' => [3, 99]])->assertOk()->assertJsonPath('data.parts.0.number', 3)->assertJsonCount(1, 'data.parts');

    // A missing part: nothing is processed, and the professor can send it and confirm again.
    $this->postJson("/api/v1/professor/media/{$media}/complete", ['parts' => array_slice($parts, 0, 2)])
        ->assertUnprocessable()->assertJsonPath('code', 'media.upload_incomplete');
    $this->getJson("/api/v1/professor/media/{$media}")->assertJsonPath('data.status', 'waiting_upload');

    $this->postJson("/api/v1/professor/media/{$media}/complete", ['parts' => $parts])->assertOk();
    $this->getJson("/api/v1/professor/media/{$media}")->assertOk()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.error', null);
    $asset = MediaAsset::query()->sole();
    expect(Lesson::query()->find($lesson)->duration_seconds)->toBe(1_200)
        ->and($asset->status)->toBe(MediaAssetStatus::Ready)
        ->and(strlen((string) $asset->key()))->toBe(16)
        ->and($asset->renditionList())->toHaveCount(3);

    // Confirming again is harmless; asking for part URLs of a finished upload is not.
    $this->postJson("/api/v1/professor/media/{$media}/complete", ['parts' => $parts])->assertOk()->assertJsonPath('data.status', 'ready');
    $this->postJson("/api/v1/professor/media/{$media}/parts", ['parts' => [1]])->assertConflict()->assertJsonPath('code', 'media.upload_not_open');
    $this->put('/api/v1/_fake/video-uploads/fake-upload-unknown/parts/1')->assertNotFound();
});

it('refuses a file that turns out larger than the limit once stored', function () {
    $course = newCourse();
    $section = $this->postJson("/api/v1/professor/courses/{$course['id']}/draft/sections", ['title' => 'Section 1'])->json('data.id');
    $lesson = $this->postJson("/api/v1/professor/courses/{$course['id']}/draft/lessons", ['section_id' => $section, 'type' => 'video', 'title' => 'Vidéo'])->json('data.id');
    $media = $this->postJson("/api/v1/professor/courses/{$course['id']}/draft/lessons/{$lesson}/media", ['size_bytes' => 1_000, 'content_type' => 'video/mp4'])->json('data.media.id');

    app(VideoProvider::class)->storeSize($media, app(CatalogSettings::class)->max_video_upload_mb * 1024 * 1024 + 1);
    $this->postJson("/api/v1/professor/media/{$media}/complete", ['parts' => [['number' => 1, 'etag' => '"fake-1"']]])
        ->assertUnprocessable()->assertJsonPath('code', 'media.upload_too_large');
    $this->getJson("/api/v1/professor/media/{$media}")->assertJsonPath('data.status', 'errored');
});

it('marks a file that cannot be processed as errored, with a message for the professor', function () {
    $course = newCourse();
    $section = $this->postJson("/api/v1/professor/courses/{$course['id']}/draft/sections", ['title' => 'Section 1'])->json('data.id');
    $lesson = $this->postJson("/api/v1/professor/courses/{$course['id']}/draft/lessons", ['section_id' => $section, 'type' => 'audio', 'title' => 'Audio'])->json('data.id');
    $upload = $this->postJson("/api/v1/professor/courses/{$course['id']}/draft/lessons/{$lesson}/media", ['size_bytes' => 1_000, 'content_type' => 'audio/mpeg'])->json('data');

    app(VideoProvider::class)->failProcessing($upload['media']['id']);
    $this->postJson("/api/v1/professor/media/{$upload['media']['id']}/complete", ['parts' => [['number' => 1, 'etag' => '"fake-1"']]])->assertOk();

    $this->getJson("/api/v1/professor/media/{$upload['media']['id']}")
        ->assertJsonPath('data.status', 'errored')
        ->assertJsonPath('data.error', __('errors.media.processing_failed'));
});

it('tells the studio which choices and limits apply', function () {
    $this->getJson('/api/v1/professor/authoring-options')
        ->assertOk()
        ->assertJsonPath('data.default_currency', 'USD')
        ->assertJsonPath('data.currencies.0.code', 'USD')
        ->assertJsonPath('data.max_preview_lessons', app(CatalogSettings::class)->max_preview_lessons)
        ->assertJsonPath('data.default_pass_mark_pct', 50)
        ->assertJsonPath('data.levels', ['beginner', 'intermediate', 'advanced', 'all'])
        ->assertJsonPath('data.languages', ['fr', 'en']);

    $this->getJson('/api/v1/professor/authoring-options')->assertJsonStructure(['data' => [
        'currencies' => [['code', 'min_minor', 'max_minor']], 'upload_limits_mb' => ['video', 'audio', 'document'], 'audiences',
    ]]);
});

it('refuses content that does not match the lesson type', function () {
    $course = newCourse();
    $section = $this->postJson("/api/v1/professor/courses/{$course['id']}/draft/sections", ['title' => 'Section 1'])->json('data.id');
    $lesson = $this->postJson("/api/v1/professor/courses/{$course['id']}/draft/lessons", ['section_id' => $section, 'type' => 'quiz', 'title' => 'Quiz'])->json('data.id');

    $this->postJson("/api/v1/professor/courses/{$course['id']}/draft/lessons/{$lesson}/media", ['size_bytes' => 1_000, 'content_type' => 'video/mp4'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'lesson.type_mismatch');
});

it('lists what is missing and refuses to submit an incomplete course', function () {
    $course = newCourse();

    $this->postJson("/api/v1/professor/courses/{$course['id']}/submit")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'course.not_ready_for_review')
        ->assertJson(['missing' => ['description', 'outcomes', 'cover', 'category', 'pricing', 'sections', 'lessons']]);
});

it('submits a complete course, locks the draft, and lets the professor withdraw', function () {
    $course = courseReadyForReview($this->professor);

    $this->getJson("/api/v1/professor/courses/{$course->id}/draft")->assertJsonPath('data.checklist.ready', true);

    $this->postJson("/api/v1/professor/courses/{$course->id}/submit")->assertOk()->assertJsonPath('data.state', 'in_review');
    expect($course->refresh()->status)->toBe(CourseStatus::InReview);

    $this->patchJson("/api/v1/professor/courses/{$course->id}/draft", ['subtitle' => 'x'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'course.draft_not_editable');

    $this->postJson("/api/v1/professor/courses/{$course->id}/withdraw")->assertOk()->assertJsonPath('data.state', 'draft');
    expect($course->refresh()->status)->toBe(CourseStatus::Draft);
});

it('does not let a suspended professor submit', function () {
    $course = courseReadyForReview($this->professor);
    $this->professor->update(['status' => 'suspended']);

    $this->postJson("/api/v1/professor/courses/{$course->id}/submit")
        ->assertForbidden()
        ->assertJsonPath('code', 'professor.suspended');
});

it('deletes never-published courses only', function () {
    $course = newCourse();
    $this->deleteJson("/api/v1/professor/courses/{$course['id']}")->assertNoContent();
    expect(Course::query()->find($course['id']))->toBeNull();

    $published = courseReadyForReview($this->professor);
    $published->update(['live_version_id' => $published->draft_version_id, 'status' => CourseStatus::Published]);
    $this->deleteJson("/api/v1/professor/courses/{$published->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'course.cannot_delete_published');
});

it('never lets a version be edited once it is in review', function () {
    $course = courseReadyForReview($this->professor);
    $this->postJson("/api/v1/professor/courses/{$course->id}/submit");

    expect($course->refresh()->draftVersion->state)->toBe(VersionState::InReview);
    $this->postJson("/api/v1/professor/courses/{$course->id}/draft/sections", ['title' => 'Nouveau'])->assertStatus(409);
});
