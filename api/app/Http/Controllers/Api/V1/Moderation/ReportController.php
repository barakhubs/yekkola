<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Moderation;

use App\Domain\Identity\Models\User;
use App\Domain\Moderation\Actions\FileReport;
use App\Domain\Moderation\Enums\ReportReason;
use App\Domain\Moderation\Enums\ReportTarget;
use App\Http\Controllers\Controller;
use App\Http\Requests\Moderation\ReportRequest;
use App\Http\Resources\Moderation\ReportResource;

/**
 * Report a course, review or professor to the moderators (PRD-06 FR-14). Reporting the same item again while the
 * report is open returns it unchanged.
 */
final class ReportController extends Controller
{
    public function store(ReportRequest $request, FileReport $file): ReportResource
    {
        /** @var User $user */
        $user = $request->user();
        $details = $request->input('details');

        return new ReportResource($file->handle(
            $user,
            ReportTarget::from((string) $request->input('type')),
            (string) $request->input('id'),
            ReportReason::from((string) $request->input('reason')),
            is_string($details) && trim($details) !== '' ? trim($details) : null,
        ));
    }
}
