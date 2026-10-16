<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Commerce;

use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Receipt;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receipts (PRD-05 FR-15) for the buyer and the student, as JSON or a PDF in the request language. Guests
 * get theirs with the order token (GuestOrderController).
 */
final class ReceiptController extends Controller
{
    /** Orders that have a receipt: paid once (refunds and reversals show on it). */
    public const RECEIPT_STATUSES = [OrderStatus::Paid, OrderStatus::PartiallyRefunded, OrderStatus::Refunded, OrderStatus::Reversed];

    /**
     * @return array{data: array<string, mixed>}
     */
    public function show(Request $request, string $order): array
    {
        return ['data' => Receipt::of($this->order($request, $order), app()->getLocale())];
    }

    public function pdf(Request $request, string $order): Response
    {
        return self::download($this->order($request, $order));
    }

    public static function download(Order $order, bool $forGuest = false): Response
    {
        $locale = app()->getLocale();
        $receipt = Receipt::of($order, $locale, $forGuest);

        return Pdf::loadView('pdf.receipt', ['receipt' => $receipt, 'locale' => $locale])
            ->setPaper('a4')
            ->download(__('receipt.filename').'-'.$receipt['number'].'.pdf');
    }

    private function order(Request $request, string $id): Order
    {
        /** @var User $user */
        $user = $request->user();

        return Order::query()
            ->where(fn (Builder $q) => $q->where('buyer_id', $user->id)->orWhere('beneficiary_id', $user->id))
            ->whereIn('status', self::RECEIPT_STATUSES)
            ->with('items', 'payments', 'beneficiary')
            ->findOrFail($id);
    }
}
