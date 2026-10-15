<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Professor;

use App\Domain\Identity\Models\User;
use App\Domain\Professors\Actions\ManageCredentials;
use App\Domain\Professors\Actions\SaveApplicationDraft;
use App\Domain\Professors\Actions\SubmitApplication;
use App\Domain\Professors\Exceptions\ProfessorException;
use App\Domain\Professors\Models\ProfessorApplication;
use App\Http\Controllers\Controller;
use App\Http\Requests\Professor\SaveApplicationRequest;
use App\Http\Requests\Professor\UploadFileRequest;
use App\Http\Resources\ProfessorApplicationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "Teach on Yekkola" application (PRD-02 FR-01–03, FR-06). Any signed-in user may apply.
 */
final class ApplicationController extends Controller
{
    /**
     * The user's open application, or their latest one (to show a decision).
     */
    public function show(Request $request): ProfessorApplicationResource
    {
        $application = ProfessorApplication::query()
            ->where('user_id', $this->user($request)->id)
            ->latest()
            ->first() ?? throw ProfessorException::applicationNotFound();

        return new ProfessorApplicationResource($application->load('province'));
    }

    /**
     * Create or autosave the draft.
     */
    public function save(SaveApplicationRequest $request, SaveApplicationDraft $save): ProfessorApplicationResource
    {
        return new ProfessorApplicationResource($save->handle($this->user($request), $request->validated())->load('province'));
    }

    /**
     * Submit for review, accepting the current professor agreement.
     */
    public function submit(Request $request, SubmitApplication $submit): ProfessorApplicationResource
    {
        $request->validate(['accept_terms' => ['required', 'boolean']]);

        return new ProfessorApplicationResource(
            $submit->handle($this->user($request), $request->boolean('accept_terms'), $request->ip())->load('province')
        );
    }

    /**
     * Attach a credential file (PDF or image, max 5).
     */
    public function addCredential(UploadFileRequest $request, SaveApplicationDraft $drafts, ManageCredentials $credentials): JsonResponse
    {
        $application = $drafts->openApplication($this->user($request)) ?? throw ProfessorException::applicationNotFound();
        $credentials->add($application, $request->file('file'));

        return (new ProfessorApplicationResource($application->refresh()->load('province')))->response()->setStatusCode(201);
    }

    /**
     * Remove a credential file.
     */
    public function removeCredential(Request $request, string $file, SaveApplicationDraft $drafts, ManageCredentials $credentials): Response
    {
        $application = $drafts->openApplication($this->user($request)) ?? throw ProfessorException::applicationNotFound();
        $credentials->remove($application, $file);

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
