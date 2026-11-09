<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Commerce\Actions\SaveCoupon;
use App\Domain\Commerce\Models\Coupon;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Settings\CommerceSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commerce\CouponRequest;
use App\Http\Requests\Commerce\UpdateCouponRequest;
use App\Http\Resources\Commerce\CouponResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/** Coupons in the back office (PRD-09 FR-13): list with usage, create, deactivate or change limits. */
final class CouponController extends Controller
{
    /** Relations behind the coupon's professor and course names. */
    private const NAMES = ['professor', 'course.liveVersion', 'course.draftVersion'];

    #[\Dedoc\Scramble\Attributes\QueryParameter('page', type: 'int', default: 1)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('per_page', type: 'int', default: 15)]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[code]', description: 'Part of the code.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[scope]', description: 'platform, professor or course.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('filter[is_active]', description: '`1` active, `0` inactive.', type: 'string')]
    #[\Dedoc\Scramble\Attributes\QueryParameter('sort', description: '`created_at` or `code`; prefix `-` for descending (default `-created_at`).', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $coupons = QueryBuilder::for(Coupon::query()->with(self::NAMES)->withCount('redemptions'))
            ->allowedFilters(AllowedFilter::exact('scope'), AllowedFilter::exact('is_active'), AllowedFilter::partial('code'))
            ->allowedSorts('created_at', 'code')
            ->defaultSort('-created_at')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return CouponResource::collection($coupons);
    }

    /**
     * What the coupon form offers: the enabled currencies (platform setting), default first.
     *
     * @return array{data: array{currencies: list<string>, default_currency: string}}
     */
    public function options(CommerceSettings $commerce): array
    {
        $currencies = array_values(array_unique([$commerce->default_currency, ...$commerce->enabled_currencies]));

        return ['data' => ['currencies' => $currencies, 'default_currency' => $commerce->default_currency]];
    }

    public function store(CouponRequest $request, SaveCoupon $save): JsonResponse
    {
        $coupon = $save->handle($request->validated(), $this->user($request));

        return (new CouponResource($coupon->load(self::NAMES)->loadCount('redemptions')))->response()->setStatusCode(201);
    }

    public function show(Coupon $coupon): CouponResource
    {
        return new CouponResource($coupon->load(self::NAMES)->loadCount('redemptions'));
    }

    public function update(UpdateCouponRequest $request, Coupon $coupon, SaveCoupon $save): CouponResource
    {
        return new CouponResource($save->handle($request->validated(), $this->user($request), $coupon)->load(self::NAMES)->loadCount('redemptions'));
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
