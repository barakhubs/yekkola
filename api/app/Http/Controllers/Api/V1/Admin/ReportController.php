<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Models\User;
use App\Domain\Moderation\Actions\ResolveReport;
use App\Domain\Moderation\Models\Report;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResolveReportRequest;
use App\Http\Resources\Admin\AdminReportResource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The reports queue (PRD-09 FR-10): open reports oldest first by default; resolving one closes every open report
 * on the same item.
 */
final class ReportController extends Controller
{
    #[\Dedoc\Scramble\Attributes\QueryParameter('page', type: 'int', default: 1)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('per_page', type: 'int', default: 15)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[status]', description: '`open` (default), `actioned`, `dismissed` or `all`.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[type]', description: '`course`, `review`, `professor`, `thread` or `reply`.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[reason]', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[target_id]', description: 'Every report on one item.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('sort', description: '`created_at`; prefix `-` for newest first (default oldest first).', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $filter = $request->query('filter');
        $status = is_array($filter) && is_string($filter['status'] ?? null) ? $filter['status'] : 'open';

        $reports = QueryBuilder::for(Report::query()->with('reporter', 'handler')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status)))
            ->allowedFilters(
                AllowedFilter::callback('status', fn () => null), // applied above, with its default
                AllowedFilter::exact('type', 'reportable_type'),
                AllowedFilter::exact('reason'),
                AllowedFilter::exact('target_id', 'reportable_id'),
            )
            ->allowedSorts('created_at')
            ->defaultSort('created_at')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        /** @var Collection<int, Report> $items */
        $items = new Collection($reports->items());
        Report::loadTargets($items);

        return AdminReportResource::collection($reports);
    }

    public function resolve(ResolveReportRequest $request, Report $report, ResolveReport $resolve): AdminReportResource
    {
        /** @var User $staff */
        $staff = $request->user();
        $report = $resolve->handle($report, $request->action(), $request->note(), $staff);

        Report::loadTargets(new Collection([$report->load('reporter', 'handler')]));

        return new AdminReportResource($report);
    }
}
