<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Professor;

use App\Domain\Finance\Models\Payout;
use App\Domain\Finance\ProfessorEarnings;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Professor\StatementRequest;
use App\Http\Resources\Finance\PayoutResource;
use App\Http\Resources\Finance\StatementLineResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The professor's earnings (PRD-08 FR-09): balances, monthly earnings, statement (also as CSV), payouts.
 */
final class EarningsController extends Controller
{
    /**
     * Held, available and paid out per currency; earnings per month for the last 12 months; next payout run.
     *
     * @return array{data: array{balances: list<array{currency: string, held: int, available: int, in_payout: int, paid_out: int}>, monthly: list<array{month: string, currency: string, amount_minor: int}>, next_payout_on: string}}
     */
    public function show(Request $request, ProfessorEarnings $earnings): array
    {
        $id = $this->profile($request)->id;

        return ['data' => [
            'balances' => $earnings->balances($id),
            'monthly' => $earnings->monthly($id),
            'next_payout_on' => $earnings->nextPayoutOn(),
        ]];
    }

    /**
     * Statement lines, newest first; filter by `type` and dates (`from`, `to`, inclusive).
     */
    public function statement(StatementRequest $request, ProfessorEarnings $earnings): AnonymousResourceCollection
    {
        $lines = $earnings->statement($this->profile($request)->id, $request->type(), $request->from(), $request->to())
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return StatementLineResource::collection($lines);
    }

    /**
     * The same statement as a CSV file (same filters).
     */
    public function statementCsv(StatementRequest $request, ProfessorEarnings $earnings): StreamedResponse
    {
        $query = $earnings->statement($this->profile($request)->id, $request->type(), $request->from(), $request->to());

        return response()->streamDownload(function () use ($query, $request): void {
            $out = fopen('php://output', 'w');
            assert($out !== false);
            fputcsv($out, ['date', 'type', 'account', 'amount_minor', 'currency', 'memo', 'reference'], escape: '');
            foreach ($query->lazy(500) as $row) {
                $line = (new StatementLineResource($row))->toArray($request);
                fputcsv($out, array_map(self::cell(...), [$line['date'], $line['type'], $line['account'], $line['amount']['amount_minor'], $line['amount']['currency'], $line['memo'], $line['reference_id']]), escape: '');
            }
            fclose($out);
        }, 'statement.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Text a spreadsheet would run as a formula (a label typed by staff) is kept as text. */
    private static function cell(mixed $value): string
    {
        $text = (string) $value;

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($text) ? "'".$text : $text;
    }

    public function payouts(Request $request): AnonymousResourceCollection
    {
        $payouts = Payout::query()->with('professor')
            ->where('professor_profile_id', $this->profile($request)->id)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($this->perPage($request));

        return PayoutResource::collection($payouts);
    }

    private function profile(Request $request): ProfessorProfile
    {
        /** @var ProfessorProfile */
        return $request->attributes->get('professor_profile');
    }
}
