<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Commerce;

use App\Domain\Commerce\Actions\CreateOrder;
use App\Domain\Commerce\Actions\StartPayment;
use App\Domain\Commerce\Actions\SyncPayment;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commerce\CourseSelectionRequest;
use App\Http\Requests\Commerce\StartPaymentRequest;
use App\Http\Resources\Commerce\OrderResource;
use App\Http\Resources\Commerce\PaymentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The buyer's orders (PRD-05): create, pay, follow the payment, history.
 */
final class OrderController extends Controller
{
    /** Waiting screen polls: re-ask the gateway at most this often per payment. */
    private const POLL_RECHECK_SECONDS = 10;

    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = Order::query()->where('buyer_id', $this->user($request)->id)
            ->with('items.course', 'payments')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($this->perPage($request));

        return OrderResource::collection($orders);
    }

    /**
     * Requires an `Idempotency-Key` header: retrying with the same key returns the same order.
     */
    public function store(CourseSelectionRequest $request, CreateOrder $create): JsonResponse
    {
        $order = $create->handle($this->user($request), $request->courseIds(), (string) $request->header('Idempotency-Key'), $request->bundleId(), $request->couponCode());

        return (new OrderResource($order->load('items.course', 'payments')))->response()->setStatusCode(201);
    }

    /**
     * Order status for the waiting screen. A payment still in flight is re-checked with the gateway
     * (at most every few seconds), so the screen moves on even if a webhook is late.
     */
    public function show(Request $request, string $order, SyncPayment $sync): OrderResource
    {
        $model = $this->order($request, $order);

        $latest = Payment::query()->where('order_id', $model->id)->latest()->first();
        if ($latest !== null && $latest->status->inFlight()
            && ($latest->last_checked_at === null || $latest->last_checked_at->lt(now()->subSeconds(self::POLL_RECHECK_SECONDS)))) {
            $sync->handle($latest);
        }

        return new OrderResource($model->refresh()->load('items.course', 'payments'));
    }

    /**
     * Starts a mobile-money payment: the payer approves it on their phone. Requires an `Idempotency-Key`.
     */
    public function pay(StartPaymentRequest $request, string $order, StartPayment $start): JsonResponse
    {
        $payment = $start->handle(
            $this->order($request, $order),
            $request->rail(),
            $request->payer(),
            (string) $request->header('Idempotency-Key'),
        );

        return (new PaymentResource($payment))->response()->setStatusCode(201);
    }

    private function order(Request $request, string $id): Order
    {
        return Order::query()->where('buyer_id', $this->user($request)->id)->findOrFail($id);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
