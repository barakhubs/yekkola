<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Learning;

use App\Domain\Identity\Models\User;
use App\Domain\Learning\Actions\StartQuizAttempt;
use App\Domain\Learning\Actions\SubmitQuizAttempt;
use App\Domain\Learning\CourseAccess;
use App\Domain\Learning\Models\QuizAttempt;
use App\Http\Controllers\Controller;
use App\Http\Requests\Learning\SubmitQuizRequest;
use App\Http\Resources\Learning\QuizAttemptResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Quiz attempts (PRD-06 FR-07). */
final class QuizAttemptController extends Controller
{
    /** My attempts on a quiz lesson, newest first. */
    public function index(Request $request, string $lesson, CourseAccess $access): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $access->lesson($user, $lesson, allowPreview: true);

        return QuizAttemptResource::collection(QuizAttempt::query()->where('user_id', $user->id)->where('lesson_uid', $lesson)
            ->with('quiz.questions.options')->latest('started_at')->get());
    }

    /** Starts an attempt (or resumes the open one): questions without answers. */
    public function store(Request $request, string $lesson, StartQuizAttempt $start): JsonResponse
    {
        $user = $this->user($request);
        $attempt = $start->handle($user, $lesson, $user->currentDeviceId());

        return (new QuizAttemptResource($attempt->load('quiz.questions.options')))->response()->setStatusCode($attempt->wasRecentlyCreated ? 201 : 200);
    }

    /** Submits answers: score, pass/fail, and the corrections with explanations. */
    public function submit(SubmitQuizRequest $request, string $attempt, SubmitQuizAttempt $submit): QuizAttemptResource
    {
        $user = $this->user($request);
        $model = QuizAttempt::query()->where('user_id', $user->id)->findOrFail($attempt);

        return new QuizAttemptResource($submit->handle($user, $model, $request->answers())->load('quiz.questions.options'));
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
