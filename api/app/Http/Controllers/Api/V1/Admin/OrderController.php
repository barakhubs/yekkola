<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Commerce\Actions\SyncPayment;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\PaymentEvent;
use App\Domain\Commerce\Models\Refund;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use App\Http\Controllers\Controller;
use App\Http\Resources\Commerce\AdminOrderDetailResource;
use App\Http\Resources\Commerce\AdminOrderResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Throwable;

/**
 * Orders and payments in the back office (PRD-09 FR-11): list with filters (incl. stuck payments), detail with the
 * full timeline, and "recheck with the gateway".
 */
final class OrderController extends Controller
{
    /** A payment still waiting this long after it started is "stuck". */
    private const STUCK_AFTER_MINUTES = 10;

    #[\Dedoc\Scramble\Attributes\QueryParameter('page', type: 'int', default: 1)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('per_page', type: 'int', default: 15)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[status]', description: 'One order status or several, comma-separated.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[number]', description: 'Order number (exact).', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[phone]', description: 'Student or buyer phone number.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[flag]', description: 'Only flagged orders: a flag, or `any`.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[currency]', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[stuck]', description: '`1`: orders with a payment still waiting after 10 minutes.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('sort', description: '`created_at` or `total_minor`; prefix `-` for newest/largest first (default `-created_at`).', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = QueryBuilder::for(Order::query()->with('items', 'payments', 'buyer', 'beneficiary'))
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('number'),
                AllowedFilter::exact('currency'),
                AllowedFilter::callback('flag', fn (Builder $q, $flag) => $flag === 'any' ? $q->whereNotNull('flag') : $q->where('flag', (string) $flag)),
                AllowedFilter::callback('phone', fn (Builder $q, $phone) => $this->byPhone($q, (string) $phone)),
                AllowedFilter::callback('stuck', fn (Builder $q, $on) => $on ? $q->whereHas('payments', fn (Builder $p) => $p
                    ->whereIn('status', [PaymentStatus::Initiated, PaymentStatus::Pending])
                    ->where('created_at', '<', now()->subMinutes(self::STUCK_AFTER_MINUTES))) : $q),
            )
            ->allowedSorts('created_at', 'total_minor')
            ->defaultSort('-created_at')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return AdminOrderResource::collection($orders);
    }

    public function show(Order $order): AdminOrderDetailResource
    {
        return $this->detail($order);
    }

    /**
     * Asks the gateway again about one payment (the status API is the source of truth) and shows the order as it
     * now stands. Safe to repeat.
     */
    public function recheck(Request $request, Payment $payment, SyncPayment $sync, RecordAudit $recordAudit): AdminOrderDetailResource
    {
        $before = $payment->status->value;
        $after = $sync->handle($payment)->status->value;
        $recordAudit->handle('payment.rechecked', $payment, ['before' => $before, 'after' => $after], causer: $request->user());

        return $this->detail($payment->order()->firstOrFail());
    }

    private function detail(Order $order): AdminOrderDetailResource
    {
        $order->load('items', 'payments', 'buyer', 'beneficiary');
        $paymentIds = $order->payments->modelKeys();
        $refunds = Refund::query()->with('item')->where('order_id', $order->id)->orderBy('created_at')->get();

        return new AdminOrderDetailResource(
            $order,
            PaymentEvent::query()->whereIn('reference', $paymentIds)->get(),
            Enrollment::query()->whereIn('order_item_id', $order->items->modelKeys())->get(),
            $refunds,
            LedgerTransaction::query()
                ->whereIn('reference_id', [$order->id, ...$paymentIds, ...$refunds->modelKeys(), ...$order->items->modelKeys()])
                ->orderBy('created_at')->orderBy('id')->get(),
        );
    }

    /** @param  Builder<\Illuminate\Database\Eloquent\Model>  $query */
    private function byPhone(Builder $query, string $phone): void
    {
        try {
            $e164 = PhoneNumber::fromString($phone)->e164;
        } catch (Throwable) {
            $query->whereRaw('1 = 0'); // not a phone number: nothing matches

            return;
        }
        $query->where(fn (Builder $q) => $q
            ->whereHas('beneficiary', fn (Builder $u) => $u->where('phone_e164', $e164))
            ->orWhereHas('buyer', fn (Builder $u) => $u->where('phone_e164', $e164)));
    }
}
