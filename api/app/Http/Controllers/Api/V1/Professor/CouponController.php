<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Professor;

use App\Domain\Commerce\Actions\SaveCoupon;
use App\Domain\Commerce\Enums\DiscountFunder;
use App\Domain\Commerce\Models\Coupon;
use App\Domain\Identity\Models\User;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commerce\CouponRequest;
use App\Http\Resources\Commerce\CouponResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/** A professor's own coupons (PRD-05 FR-12): their courses only, professor-funded. */
final class CouponController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $coupons = QueryBuilder::for($this->own($request)->withCount('redemptions'))
            ->allowedFilters(AllowedFilter::exact('is_active'), AllowedFilter::partial('code'))
            ->allowedSorts('created_at', 'code')
            ->defaultSort('-created_at')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return CouponResource::collection($coupons);
    }

    public function store(CouponRequest $request, SaveCoupon $save): JsonResponse
    {
        $coupon = $save->handle($request->coupon(), $this->user($request), professor: $this->professor($request));

        return (new CouponResource($coupon->loadCount('redemptions')))->response()->setStatusCode(201);
    }

    public function update(CouponRequest $request, string $coupon, SaveCoupon $save): CouponResource
    {
        $model = $this->own($request)->findOrFail($coupon);

        return new CouponResource($save->handle($request->coupon(), $this->user($request), $model)->loadCount('redemptions'));
    }

    /**
     * Coupons this professor funds — never platform coupons staff made for them.
     *
     * @return Builder<Coupon>
     */
    private function own(Request $request): Builder
    {
        return Coupon::query()->where('professor_profile_id', $this->professor($request)->id)->where('funded_by', DiscountFunder::Professor);
    }

    private function professor(Request $request): ProfessorProfile
    {
        return ProfessorProfile::query()->where('user_id', $this->user($request)->id)->firstOrFail();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
