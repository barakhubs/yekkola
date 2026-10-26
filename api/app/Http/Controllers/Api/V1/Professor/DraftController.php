<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Professor;

use App\Domain\Authoring\Actions\CourseReview;
use App\Domain\Authoring\Actions\EditDraft;
use App\Domain\Authoring\Actions\SetLessonContent;
use App\Domain\Authoring\Exceptions\AuthoringException;
use App\Domain\Authoring\Models\Course;
use App\Domain\Authoring\Models\CourseVersion;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Authoring\CoverRequest;
use App\Http\Requests\Authoring\DocumentRequest;
use App\Http\Requests\Authoring\QuizRequest;
use App\Http\Requests\Authoring\ReorderCurriculumRequest;
use App\Http\Requests\Authoring\SectionRequest;
use App\Http\Requests\Authoring\StoreLessonRequest;
use App\Http\Requests\Authoring\UpdateDraftRequest;
use App\Http\Requests\Authoring\UpdateLessonRequest;
use App\Http\Resources\Authoring\CourseVersionResource;
use App\Http\Resources\Authoring\MediaAssetResource;
use App\Http\Resources\Authoring\StudioLessonResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The course builder (PRD-03 FR-02–08): the draft version's details, curriculum, and lesson content.
 * Every write goes through EditDraft, which refuses unless the draft is editable.
 */
final class DraftController extends Controller
{
    /**
     * The draft with its curriculum and the submit checklist.
     */
    public function show(Request $request, string $course, CourseReview $review): CourseVersionResource
    {
        $model = $this->course($request, $course);
        $draft = $model->draftVersion ?? throw AuthoringException::noDraft();

        return $this->present($draft)->withChecklist($review->checklist($model, $draft));
    }

    public function update(UpdateDraftRequest $request, string $course, EditDraft $edit): CourseVersionResource
    {
        return $this->present($edit->updateDetails($this->course($request, $course), $request->validated()));
    }

    public function cover(CoverRequest $request, string $course, EditDraft $edit): CourseVersionResource
    {
        return $this->present($edit->setCover($this->course($request, $course), $request->file('cover')));
    }

    public function storeSection(SectionRequest $request, string $course, EditDraft $edit): JsonResponse
    {
        $section = $edit->addSection($this->course($request, $course), (string) $request->input('title'));

        return new JsonResponse(['data' => ['id' => $section->id, 'title' => $section->title, 'position' => $section->position]], 201);
    }

    public function updateSection(SectionRequest $request, string $course, string $section, EditDraft $edit): JsonResponse
    {
        $updated = $edit->updateSection($this->course($request, $course), $section, (string) $request->input('title'));

        return new JsonResponse(['data' => ['id' => $updated->id, 'title' => $updated->title, 'position' => $updated->position]]);
    }

    public function destroySection(Request $request, string $course, string $section, EditDraft $edit): Response
    {
        $edit->deleteSection($this->course($request, $course), $section);

        return response()->noContent();
    }

    public function storeLesson(StoreLessonRequest $request, string $course, EditDraft $edit): JsonResponse
    {
        /** @var array{section_id: string, type: string, title: string, description?: string|null, is_preview?: bool} $data */
        $data = $request->validated();
        $lesson = $edit->addLesson($this->course($request, $course), $data);

        return (new StudioLessonResource($lesson->load('mediaAsset', 'quiz.questions.options')))->response()->setStatusCode(201);
    }

    public function updateLesson(UpdateLessonRequest $request, string $course, string $lesson, EditDraft $edit): StudioLessonResource
    {
        /** @var array{title?: string, description?: string|null, is_preview?: bool} $data */
        $data = $request->validated();

        return new StudioLessonResource($edit->updateLesson($this->course($request, $course), $lesson, $data)->load('mediaAsset', 'quiz.questions.options'));
    }

    public function destroyLesson(Request $request, string $course, string $lesson, EditDraft $edit): Response
    {
        $edit->deleteLesson($this->course($request, $course), $lesson);

        return response()->noContent();
    }

    public function reorder(ReorderCurriculumRequest $request, string $course, EditDraft $edit): CourseVersionResource
    {
        /** @var list<array{id: string, lessons: list<string>}> $order */
        $order = $request->validated('sections');

        return $this->present($edit->reorder($this->course($request, $course), $order));
    }

    /**
     * Direct-upload URL for a video/audio lesson. The browser uploads to it; processing status follows.
     */
    public function mediaUpload(Request $request, string $course, string $lesson, SetLessonContent $content): JsonResponse
    {
        // The studio's origin (the web app), from config — never from the request.
        $origin = (string) (config('cors.allowed_origins')[0] ?? config('app.url'));
        $result = $content->createMediaUpload($this->course($request, $course), $lesson, $origin);

        return new JsonResponse(['data' => [
            'media' => new MediaAssetResource($result['asset']),
            'upload_url' => $result['upload_url'],
        ]], 201);
    }

    public function document(DocumentRequest $request, string $course, string $lesson, SetLessonContent $content): StudioLessonResource
    {
        $updated = $content->attachDocument($this->course($request, $course), $lesson, $request->file('document'));

        return new StudioLessonResource($updated->load('mediaAsset', 'quiz.questions.options'));
    }

    public function quiz(QuizRequest $request, string $course, string $lesson, SetLessonContent $content): StudioLessonResource
    {
        /** @var array{pass_mark_pct: int, time_limit_seconds?: int|null, max_attempts?: int|null, shuffle_questions?: bool, questions: list<array{type: string, prompt: string, explanation?: string|null, points?: int, options: list<array{text: string, is_correct: bool}>}>} $data */
        $data = $request->validated();

        return new StudioLessonResource($content->saveQuiz($this->course($request, $course), $lesson, $data)->load('mediaAsset'));
    }

    private function present(CourseVersion $version): CourseVersionResource
    {
        return new CourseVersionResource($version->refresh()->load(
            'sections.lessons.mediaAsset',
            'sections.lessons.quiz.questions.options',
            'sections.lessons.media',
        ));
    }

    private function course(Request $request, string $id): Course
    {
        /** @var ProfessorProfile $professor */
        $professor = $request->attributes->get('professor_profile');

        return Course::query()->where('professor_profile_id', $professor->id)->findOrFail($id);
    }
}
