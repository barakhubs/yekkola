<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Commerce;

use App\Domain\Commerce\Actions\AvailableRails;
use App\Domain\Commerce\Actions\QuoteOrder;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Currency;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commerce\CourseSelectionRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Checkout helpers (PRD-05): server-side quote and which operators are available.
 */
final class CheckoutController extends Controller
{
    /**
     * Price for the selected courses; courses already owned are left out.
     *
     * @return array{data: array<string, mixed>}
     */
    public function quote(CourseSelectionRequest $request, QuoteOrder $quote): array
    {
        /** @var User $user */
        $user = $request->user();

        return ['data' => $quote->handle($user, $request->courseIds(), $request->bundleId(), $request->couponCode())->toArray()];
    }

    /**
     * Operators and whether each can take a payment now in the given currency (FR-19).
     *
     * @return array{data: array<string, bool>}
     */
    public function rails(Request $request, AvailableRails $rails): array
    {
        $request->validate(['currency' => ['required', Rule::enum(Currency::class)]]);

        return ['data' => $rails->handle(Currency::from($request->string('currency')->toString()))];
    }
}
