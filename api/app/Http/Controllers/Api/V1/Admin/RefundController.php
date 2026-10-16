<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Commerce\Actions\RefundLifecycle;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\Refund;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Platform\Settings\CommerceSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StaffNoteRequest;
use App\Http\Resources\Commerce\RefundResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Refund queue (PRD-09 FR-12, `finance.refunds`): requests with the policy facts, approve/reject with a
 * note, retry failed payouts, and send back money a payment collected but never applied.
 */
final class RefundController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $refunds = QueryBuilder::for(Refund::query()->with('order', 'item'))
            ->allowedFilters(AllowedFilter::exact('status'), AllowedFilter::exact('kind'))
            ->allowedSorts('created_at', 'amount_minor')
            ->defaultSort('created_at')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return RefundResource::collection($refunds);
    }

    public function show(Refund $refund, CommerceSettings $settings): RefundResource
    {
        return $this->present($refund, $settings);
    }

    public function approve(StaffNoteRequest $request, Refund $refund, RefundLifecycle $refunds, CommerceSettings $settings): RefundResource
    {
        return $this->present($refunds->approve($refund, $this->staff($request), $request->note()), $settings);
    }

    public function reject(StaffNoteRequest $request, Refund $refund, RefundLifecycle $refunds, CommerceSettings $settings): RefundResource
    {
        return $this->present($refunds->reject($refund, $this->staff($request), (string) $request->note()), $settings);
    }

    public function retry(Request $request, Refund $refund, RefundLifecycle $refunds, CommerceSettings $settings): RefundResource
    {
        return $this->present($refunds->retry($refund, $this->staff($request)), $settings);
    }

    /** Sends back what a payment collected but never applied (flagged orders). */
    public function refundUnapplied(StaffNoteRequest $request, Payment $payment, RefundLifecycle $refunds): JsonResponse
    {
        $refund = $refunds->settleUnapplied($payment, $this->staff($request), $request->note());

        return (new RefundResource($refund->load('order', 'item')))->response()->setStatusCode(201);
    }

    private function present(Refund $refund, CommerceSettings $settings): RefundResource
    {
        $refund->refresh()->load('order', 'item'); // the payout job may already have run
        $progress = $refund->order_item_id === null ? null
            : Enrollment::query()->where('order_item_id', $refund->order_item_id)->value('progress_pct');

        return (new RefundResource($refund))->withPolicy([
            'days_since_payment' => $refund->order->paid_at === null ? null : (int) $refund->order->paid_at->diffInDays(now()),
            'refund_window_days' => $settings->refund_window_days,
            'progress_pct' => $progress === null ? null : (int) $progress,
            'max_progress_pct' => $settings->refund_max_progress_pct,
        ]);
    }

    private function staff(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
