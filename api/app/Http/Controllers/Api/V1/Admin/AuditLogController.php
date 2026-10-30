<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Platform\Models\Activity;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AuditEntryResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The audit log (PRD-09 FR-16): every admin action and settings change, newest first, with who, on what, the
 * details (before/after, reason) and where from. Read-only; entries can't be changed.
 */
final class AuditLogController extends Controller
{
    #[\Dedoc\Scramble\Attributes\QueryParameter('page', type: 'int', default: 1)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('per_page', type: 'int', default: 15)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[event]', description: 'An event (`refund.approved`) or a family (`refund`).', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[causer_id]', description: 'The staff member who acted.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[subject_id]', description: 'What they acted on (a user, refund, payout…).', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[from]', type: 'string', format: 'date')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[to]', type: 'string', format: 'date')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $entries = QueryBuilder::for(Activity::query()->with('causer')->where('log_name', RecordAudit::LOG_NAME))
            ->allowedFilters(
                AllowedFilter::callback('event', fn (Builder $q, $event) => str_contains((string) $event, '.')
                    ? $q->where('event', (string) $event)
                    : $q->where('event', 'like', addcslashes((string) $event, '%_\\').'.%')),
                AllowedFilter::exact('causer_id'),
                AllowedFilter::exact('subject_id'),
                AllowedFilter::callback('from', fn (Builder $q, $date) => $q->where('created_at', '>=', (string) $date)),
                AllowedFilter::callback('to', fn (Builder $q, $date) => $q->where('created_at', '<', now()->parse((string) $date)->addDay())),
            )
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return AuditEntryResource::collection($entries);
    }
}
