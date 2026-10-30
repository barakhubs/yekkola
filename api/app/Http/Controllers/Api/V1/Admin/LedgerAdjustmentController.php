<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Finance\Actions\PostManualAdjustment;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Identity\Models\User;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustmentRequest;
use Illuminate\Http\JsonResponse;

/**
 * Manual adjustment of a professor's available balance (PRD-08 FR-11): finance only, audited, idempotent.
 */
final class LedgerAdjustmentController extends Controller
{
    public function store(AdjustmentRequest $request, PostManualAdjustment $adjust): JsonResponse
    {
        /** @var User $staff */
        $staff = $request->user();
        $professor = ProfessorProfile::query()->findOrFail($request->string('professor_profile_id')->toString());

        $transaction = $adjust->handle($professor, $request->amount(), $request->toProfessor(), $request->string('label')->toString(), $request->string('reason')->toString(), $staff, (string) $request->header('Idempotency-Key'));
        $transaction->load('entries.account');

        return new JsonResponse(['data' => [
            'id' => $transaction->id,
            'type' => $transaction->type->value,
            'memo' => $transaction->memo,
            'created_at' => $transaction->created_at->toIso8601String(),
            'entries' => $transaction->entries->map(fn (LedgerEntry $e) => [
                'account' => $e->account->type->value,
                'owner_id' => $e->account->owner_id,
                'direction' => $e->direction->value,
                'amount_minor' => $e->amount_minor,
                'currency' => $e->currency,
            ])->values()->all(),
        ]], 201);
    }
}
