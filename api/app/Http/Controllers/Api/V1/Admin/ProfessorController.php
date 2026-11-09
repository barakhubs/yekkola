<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Models\User;
use App\Domain\Professors\Actions\ManageProfessorStanding;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StaffNoteRequest;
use App\Http\Requests\Admin\UpdateProfessorRequest;
use App\Http\Resources\Admin\AdminProfessorResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Professors in the back office (PRD-09 FR-06): list, detail, revenue-share override, suspend/reinstate.
 */
final class ProfessorController extends Controller
{
    #[\Dedoc\Scramble\Attributes\QueryParameter('page', type: 'int', default: 1)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('per_page', type: 'int', default: 15)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[search]', description: 'Part of the display name or slug.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[status]', description: 'active or suspended.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[kyc_status]', description: 'none, pending, verified or rejected.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[province_id]', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('sort', description: '`display_name`, `created_at` or `student_count`; prefix `-` for descending (default `-created_at`).', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $professors = QueryBuilder::for(ProfessorProfile::query()->with('user', 'province', 'media'))
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('kyc_status'),
                AllowedFilter::exact('province_id'),
                AllowedFilter::callback('search', fn ($q, $value) => $q->where(fn ($q) => $q
                    ->where('display_name', 'ilike', '%'.$value.'%')
                    ->orWhere('slug', 'ilike', '%'.$value.'%'))),
            )
            ->allowedSorts('display_name', 'created_at', 'student_count')
            ->defaultSort('-created_at')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return AdminProfessorResource::collection($professors);
    }

    public function show(ProfessorProfile $professor): AdminProfessorResource
    {
        return new AdminProfessorResource($professor->load('user', 'province'));
    }

    /**
     * Revenue-share override (null = platform default). Future sales only.
     */
    public function update(UpdateProfessorRequest $request, ProfessorProfile $professor, ManageProfessorStanding $standing): AdminProfessorResource
    {
        $bps = $request->input('revenue_share_bps');

        return new AdminProfessorResource($standing->setRevenueShare(
            $professor,
            $this->staff($request),
            $bps === null ? null : (int) $bps,
            (string) $request->input('note'),
        )->load('user', 'province'));
    }

    public function suspend(StaffNoteRequest $request, ProfessorProfile $professor, ManageProfessorStanding $standing): AdminProfessorResource
    {
        return new AdminProfessorResource($standing->suspend($professor, $this->staff($request), (string) $request->note())->load('user', 'province'));
    }

    public function reinstate(StaffNoteRequest $request, ProfessorProfile $professor, ManageProfessorStanding $standing): AdminProfessorResource
    {
        return new AdminProfessorResource($standing->reinstate($professor, $this->staff($request), (string) $request->note())->load('user', 'province'));
    }

    private function staff(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
