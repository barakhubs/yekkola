<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Commerce;

use App\Domain\Commerce\Actions\BuyForStudent;
use App\Domain\Commerce\Actions\CreateOrder;
use App\Domain\Commerce\Actions\RefreshOrderStatus;
use App\Domain\Commerce\Actions\StartPayment;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\NewOrder;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commerce\CourseSelectionRequest;
use App\Http\Requests\Commerce\StartPaymentRequest;
use App\Http\Resources\Commerce\OrderResource;
use App\Http\Resources\Commerce\PaymentResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Orders of the signed-in user (PRD-05): create (for themselves or another student, FR-14), pay, follow the
 * payment, history. A student also sees orders bought for them; only the buyer pays.
 */
final class OrderController extends Controller
{
    /**
     * My orders, newest first (PRD-05 FR-17).
     */
    #[\Dedoc\Scramble\Attributes\QueryParameter('page', type: 'int', default: 1)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('per_page', type: 'int', default: 15)]
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = $this->visible($request)
            ->with('items.course', 'items.activeEnrollment', 'items.refunds', 'payments')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($this->perPage($request));

        return OrderResource::collection($orders);
    }

    /**
     * Requires an `Idempotency-Key` header: retrying with the same key returns the same order. With
     * `beneficiary_phone`, the courses go to that student (admin setting "separate payer").
     */
    public function store(CourseSelectionRequest $request, CreateOrder $create, BuyForStudent $buyFor): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key');
        $student = $request->beneficiaryPhone();

        $order = $student === null
            ? $create->handle(NewOrder::forSelf($this->user($request), $key, $request->courseIds(), $request->bundleId(), $request->couponCode(), $request->payerPhone()))
            : $buyFor->handle($this->user($request), $student, $key, app()->getLocale(), $request->courseIds(), $request->bundleId(), $request->couponCode(), $request->payerPhone())['order'];

        return (new OrderResource($order->load('items.course', 'payments')))->response()->setStatusCode(201);
    }

    /**
     * Order status for the waiting screen.
     */
    public function show(Request $request, string $order, RefreshOrderStatus $refresh): OrderResource
    {
        $model = $refresh->handle($this->visible($request)->findOrFail($order));

        return new OrderResource($model->load('items.course', 'items.activeEnrollment', 'items.refunds', 'payments'));
    }

    /**
     * Starts a mobile-money payment: the payer approves it on their phone. Requires an `Idempotency-Key`.
     * The number defaults to the payer named on the order, then to the buyer's own.
     */
    public function pay(StartPaymentRequest $request, string $order, StartPayment $start): JsonResponse
    {
        $user = $this->user($request);
        $model = Order::query()->where('buyer_id', $user->id)->findOrFail($order);

        $payment = $start->handle($model, $request->rail(), $request->payer($model->payer() ?? $user->phone), (string) $request->header('Idempotency-Key'));

        return (new PaymentResource($payment))->response()->setStatusCode(201);
    }

    /**
     * @return Builder<Order>
     */
    private function visible(Request $request): Builder
    {
        $id = $this->user($request)->id;

        // A student sees orders others bought for them only once paid: unpaid ones are the buyer's business.
        return Order::query()->where(fn (Builder $q) => $q->where('buyer_id', $id)->orWhere(fn (Builder $b) => $b
            ->where('beneficiary_id', $id)
            ->whereIn('status', [OrderStatus::Paid, OrderStatus::Reversed, OrderStatus::Refunded, OrderStatus::PartiallyRefunded])));
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
