<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Commerce;

use App\Domain\Commerce\Actions\RequestRefund;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Refund;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commerce\RefundRequestRequest;
use App\Http\Resources\Commerce\RefundResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** A student's refund requests (PRD-05 FR-16). */
final class RefundController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $id = $this->user($request)->id;
        $refunds = Refund::query()->with('order', 'item')
            ->whereHas('order', fn ($q) => $q->where('buyer_id', $id)->orWhere('beneficiary_id', $id))
            ->latest()->paginate($this->perPage($request));

        return RefundResource::collection($refunds);
    }

    /**
     * Asks for one course of a paid order to be refunded; staff decide.
     */
    public function store(RefundRequestRequest $request, string $order, RequestRefund $requestRefund): JsonResponse
    {
        $data = $request->validated();
        $user = $this->user($request);
        $model = Order::query()->where(fn ($q) => $q->where('buyer_id', $user->id)->orWhere('beneficiary_id', $user->id))->findOrFail($order);

        $refund = $requestRefund->handle($user, $model, (string) $data['order_item_id'], (string) $data['reason']);

        return (new RefundResource($refund->load('order', 'item')))->response()->setStatusCode(201);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
