<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Commerce\Actions\SaveCoupon;
use App\Domain\Commerce\Models\Coupon;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commerce\CouponRequest;
use App\Http\Resources\Commerce\CouponResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/** Coupons in the back office (PRD-09 FR-13): list with usage, create, deactivate or change limits. */
final class CouponController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $coupons = QueryBuilder::for(Coupon::query()->withCount('redemptions'))
            ->allowedFilters(AllowedFilter::exact('scope'), AllowedFilter::exact('is_active'), AllowedFilter::partial('code'))
            ->allowedSorts('created_at', 'code')
            ->defaultSort('-created_at')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return CouponResource::collection($coupons);
    }

    public function store(CouponRequest $request, SaveCoupon $save): JsonResponse
    {
        return (new CouponResource($save->handle($request->coupon(), $this->user($request))))->response()->setStatusCode(201);
    }

    public function show(Coupon $coupon): CouponResource
    {
        return new CouponResource($coupon->loadCount('redemptions'));
    }

    public function update(CouponRequest $request, Coupon $coupon, SaveCoupon $save): CouponResource
    {
        return new CouponResource($save->handle($request->coupon(), $this->user($request), $coupon)->loadCount('redemptions'));
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
