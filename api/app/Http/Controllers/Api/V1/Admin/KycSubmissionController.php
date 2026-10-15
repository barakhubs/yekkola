<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Professors\Actions\ReviewKyc;
use App\Domain\Professors\Models\KycSubmission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StaffNoteRequest;
use App\Http\Resources\KycSubmissionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identity checks (PRD-02 FR-08, PRD-09 FR-06). Requires the `kyc.view` permission; every file view
 * is audited (BR-04) and files are only ever streamed through this API.
 */
final class KycSubmissionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $submissions = QueryBuilder::for(KycSubmission::query()->with('profile'))
            ->allowedFilters(AllowedFilter::exact('status'), AllowedFilter::exact('document_type'))
            ->allowedSorts('created_at', 'reviewed_at')
            ->defaultSort('created_at')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        $collection = KycSubmissionResource::collection($submissions);
        $collection->collection->each(fn (KycSubmissionResource $r) => $r->forStaff());

        return $collection;
    }

    public function show(KycSubmission $submission): KycSubmissionResource
    {
        return (new KycSubmissionResource($submission->load('profile')))->forStaff();
    }

    /**
     * Stream one document image (front, back, selfie). Audited.
     */
    public function file(Request $request, KycSubmission $submission, string $kind, RecordAudit $audit): Response
    {
        abort_unless(in_array($kind, KycSubmission::FILES, true), 404);
        $media = $submission->getFirstMedia($kind);
        abort_if($media === null, 404);

        $audit->handle('kyc.file_viewed', $submission, ['file' => $kind]);

        return $media->toInlineResponse($request);
    }

    public function verify(Request $request, KycSubmission $submission, ReviewKyc $review): KycSubmissionResource
    {
        return (new KycSubmissionResource($review->verify($submission, $this->staff($request))->load('profile')))->forStaff();
    }

    public function reject(StaffNoteRequest $request, KycSubmission $submission, ReviewKyc $review): KycSubmissionResource
    {
        return (new KycSubmissionResource($review->reject($submission, $this->staff($request), (string) $request->note())->load('profile')))->forStaff();
    }

    private function staff(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
