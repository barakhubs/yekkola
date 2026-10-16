<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Commerce;

use App\Domain\Commerce\Actions\BuyForStudent;
use App\Domain\Commerce\Actions\RefreshOrderStatus;
use App\Domain\Commerce\Actions\StartPayment;
use App\Domain\Commerce\Models\Order;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Shared\Exceptions\InvalidPhoneNumber;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commerce\GuestOrderRequest;
use App\Http\Requests\Commerce\StartPaymentRequest;
use App\Http\Resources\Commerce\OrderResource;
use App\Http\Resources\Commerce\PaymentResource;
use App\Integrations\BotChallenge\BotChallenge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A parent with no account pays for a student (PRD-05 FR-14). Creating the order returns a one-time
 * `access_token` (the same on any retry with the same `Idempotency-Key`); following and paying it require that
 * token in the `X-Order-Token` header, for 30 days.
 */
final class GuestOrderController extends Controller
{
    public const TOKEN_HEADER = 'X-Order-Token';

    /**
     * Requires an `Idempotency-Key` header and a passed bot challenge (`bot_token`).
     */
    public function store(GuestOrderRequest $request, BuyForStudent $buyFor, BotChallenge $bots): JsonResponse
    {
        if (! $bots->verify((string) $request->input('bot_token'), (string) $request->ip())) {
            throw IdentityException::botChallengeFailed();
        }

        $student = $request->beneficiaryPhone() ?? throw InvalidPhoneNumber::make(); // required by the request

        $result = $buyFor->handle(null, $student, (string) $request->header('Idempotency-Key'), app()->getLocale(),
            $request->courseIds(), $request->bundleId(), $request->couponCode(), $request->payerPhone());

        return (new OrderResource($result['order']->load('items.course', 'payments')))
            ->additional(['access_token' => $result['access_token']])
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, string $order, RefreshOrderStatus $refresh): OrderResource
    {
        return new OrderResource($refresh->handle($this->order($request, $order))->load('items.course', 'payments'));
    }

    /**
     * Requires an `Idempotency-Key`. The number defaults to the payer named on the order.
     */
    public function pay(StartPaymentRequest $request, string $order, StartPayment $start): JsonResponse
    {
        $model = $this->order($request, $order);
        $payment = $start->handle($model, $request->rail(), $request->payer($model->payer()), (string) $request->header('Idempotency-Key'));

        return (new PaymentResource($payment))->response()->setStatusCode(201);
    }

    public function receipt(Request $request, string $order): Response
    {
        $model = $this->order($request, $order);
        abort_unless(in_array($model->status, ReceiptController::RECEIPT_STATUSES, true), 404);

        return ReceiptController::download($model->load('items', 'payments', 'beneficiary'));
    }

    /** Only a guest order, and only with its token (compared by hash). */
    private function order(Request $request, string $id): Order
    {
        $token = (string) $request->header(self::TOKEN_HEADER);
        abort_if($token === '', 404);

        return Order::query()->whereNull('buyer_id')->where('guest_token_hash', hash('sha256', $token))
            ->where('created_at', '>', now()->subDays((int) config('yekkola.checkout.guest_token_days')))
            ->findOrFail($id);
    }
}
