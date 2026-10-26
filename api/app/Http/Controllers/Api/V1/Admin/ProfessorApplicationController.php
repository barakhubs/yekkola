<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Professors\Actions\ReviewApplication;
use App\Domain\Professors\Enums\ApplicationStatus;
use App\Domain\Professors\Models\ProfessorApplication;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StaffNoteRequest;
use App\Http\Resources\Admin\AdminProfessorApplicationResource;
use App\Http\Resources\Admin\AdminProfessorResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\Response;

/**
 * Professor application queue (PRD-02 FR-04, PRD-09 FR-05). Drafts are never shown to staff.
 */
final class ProfessorApplicationController extends Controller
{
    /**
     * Queue: filter by status/subject/date, oldest submissions first by default.
     */
    #[\Dedoc\Scramble\Attributes\QueryParameter('page', type: 'int', default: 1)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('per_page', type: 'int', default: 15)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[status]', description: 'One status or several, comma-separated (e.g. `submitted,under_review`).', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[subject]', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[submitted_from]', type: 'string', format: 'date')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[submitted_to]', type: 'string', format: 'date')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('sort', description: '`submitted_at`, `decided_at` or `created_at`; prefix `-` for newest first.', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $applications = QueryBuilder::for(ProfessorApplication::query()->where('status', '!=', ApplicationStatus::Draft)->with('user', 'province', 'media'))
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::callback('subject', fn ($q, $value) => $q->whereJsonContains('subjects', $value)),
                AllowedFilter::callback('submitted_from', fn ($q, $value) => $q->where('submitted_at', '>=', $value)),
                AllowedFilter::callback('submitted_to', fn ($q, $value) => $q->where('submitted_at', '<=', $value)),
            )
            ->allowedSorts('submitted_at', 'decided_at', 'created_at')
            ->defaultSort('submitted_at')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return AdminProfessorApplicationResource::collection($applications);
    }

    public function show(ProfessorApplication $application): AdminProfessorApplicationResource
    {
        abort_if($application->status === ApplicationStatus::Draft, 404);

        return new AdminProfessorApplicationResource($application->load('user', 'province'));
    }

    public function startReview(Request $request, ProfessorApplication $application, ReviewApplication $review): AdminProfessorApplicationResource
    {
        return new AdminProfessorApplicationResource($review->startReview($application, $this->staff($request))->load('user', 'province'));
    }

    public function approve(StaffNoteRequest $request, ProfessorApplication $application, ReviewApplication $review): AdminProfessorResource
    {
        return new AdminProfessorResource($review->approve($application, $this->staff($request), $request->note())->load('user', 'province'));
    }

    public function reject(StaffNoteRequest $request, ProfessorApplication $application, ReviewApplication $review): AdminProfessorApplicationResource
    {
        return new AdminProfessorApplicationResource($review->reject($application, $this->staff($request), (string) $request->note())->load('user', 'province'));
    }

    public function requestInfo(StaffNoteRequest $request, ProfessorApplication $application, ReviewApplication $review): AdminProfessorApplicationResource
    {
        return new AdminProfessorApplicationResource($review->requestInfo($application, $this->staff($request), (string) $request->note())->load('user', 'province'));
    }

    /**
     * Stream a credential file to staff (audited).
     */
    public function credentialFile(Request $request, ProfessorApplication $application, string $file, RecordAudit $audit): Response
    {
        abort_if($application->status === ApplicationStatus::Draft, 404);

        /** @var Media|null $media */
        $media = $application->getMedia(ProfessorApplication::CREDENTIALS)->firstWhere('uuid', $file);
        abort_if($media === null, 404);

        $audit->handle('professor_application.credential_viewed', $application, ['file' => $media->uuid]);

        $response = $media->toInlineResponse($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "sandbox; default-src 'none'");

        return $response;
    }

    private function staff(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
