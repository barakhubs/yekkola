<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Finance\Actions\BuildPayoutBatch;
use App\Domain\Finance\Actions\ReviewPayoutBatch;
use App\Domain\Finance\Models\Payout;
use App\Domain\Finance\Models\PayoutBatch;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BuildPayoutBatchRequest;
use App\Http\Requests\Admin\StaffNoteRequest;
use App\Http\Resources\Finance\PayoutBatchResource;
use App\Http\Resources\Finance\PayoutResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Professor payout batches (PRD-08 FR-06–FR-08, PRD-09): list, build now, review lines, leave one out, approve.
 */
final class PayoutBatchController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $batches = QueryBuilder::for(PayoutBatch::query()->with('payouts'))
            ->allowedFilters(AllowedFilter::exact('status'), AllowedFilter::exact('currency'))
            ->allowedSorts('created_at')
            ->defaultSort('-created_at')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return PayoutBatchResource::collection($batches);
    }

    /**
     * Builds a draft batch now, outside the schedule. 409 `payout.batch_open` while one is open in that currency.
     */
    public function store(BuildPayoutBatchRequest $request, BuildPayoutBatch $build): JsonResponse
    {
        $batch = $build->handle($request->currency(), $this->staff($request));

        return (new PayoutBatchResource($batch->load('payouts.professor')))->response()->setStatusCode(201);
    }

    public function show(PayoutBatch $batch): PayoutBatchResource
    {
        return new PayoutBatchResource($batch->load('payouts.professor'));
    }

    /**
     * Sends every line still in the batch. Never for a batch that pays the approver.
     */
    public function approve(Request $request, PayoutBatch $batch, ReviewPayoutBatch $review): PayoutBatchResource
    {
        return new PayoutBatchResource($review->approve($batch, $this->staff($request))->load('payouts.professor'));
    }

    /**
     * Leaves one line out of a draft batch, with a reason (`note`). The money stays available.
     */
    public function exclude(StaffNoteRequest $request, Payout $payout, ReviewPayoutBatch $review): PayoutResource
    {
        return new PayoutResource($review->exclude($payout, $this->staff($request), (string) $request->note())->load('professor'));
    }

    private function staff(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
