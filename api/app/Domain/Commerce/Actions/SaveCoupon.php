<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Authoring\Models\Course;
use App\Domain\Commerce\Enums\CouponScope;
use App\Domain\Commerce\Enums\DiscountFunder;
use App\Domain\Commerce\Enums\DiscountType;
use App\Domain\Commerce\Models\Coupon;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Platform\Settings\CommerceSettings;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Domain\Shared\Enums\Currency;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a coupon (PRD-05 FR-11, FR-12, PRD-09 FR-13). Professors make coupons only for their
 * own courses, always professor-funded. Once created, only the operational fields change (active, end
 * date, limits) — never what a coupon is worth. Audited.
 */
final class SaveCoupon
{
    /** Fields that may change after creation. */
    public const UPDATABLE = ['is_active', 'ends_at', 'max_redemptions', 'per_user_limit'];

    public function __construct(
        private readonly CommerceSettings $settings,
        private readonly RecordAudit $recordAudit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated.
     * @param  ProfessorProfile|null  $professor  Set when a professor saves their own coupon.
     */
    public function handle(array $data, User $actor, ?Coupon $coupon = null, ?ProfessorProfile $professor = null): Coupon
    {
        if ($coupon !== null) {
            $before = $coupon->only(self::UPDATABLE);
            $coupon->fill(array_intersect_key($data, array_flip(self::UPDATABLE)))->save();
            $this->recordAudit->handle('coupon.updated', $coupon, ['before' => $before, 'after' => $coupon->only(self::UPDATABLE)], causer: $actor);

            return $coupon;
        }

        if ($professor !== null) {
            $data['funded_by'] = DiscountFunder::Professor->value;
            $data['scope'] = isset($data['course_id']) ? CouponScope::Course->value : CouponScope::Professor->value;
            $data['professor_profile_id'] = $professor->id;
        }

        $this->assertConsistent($data, $professor);

        if ($professor === null && $data['scope'] !== CouponScope::Professor->value) {
            $data['professor_profile_id'] = null;
        }
        if ($data['scope'] !== CouponScope::Course->value) {
            $data['course_id'] = null;
        }

        $coupon = Coupon::query()->create([
            ...$data,
            'code' => Coupon::normalize((string) $data['code']),
            'currency' => $data['discount_type'] === DiscountType::Fixed->value ? $data['currency'] : null,
            'created_by' => $actor->id,
        ]);
        $this->recordAudit->handle('coupon.created', $coupon, ['code' => $coupon->code], causer: $actor);

        return $coupon;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertConsistent(array $data, ?ProfessorProfile $professor): void
    {
        $scope = CouponScope::from((string) $data['scope']);

        if ($scope === CouponScope::Course) {
            $course = Course::query()->find($data['course_id'] ?? null);
            if ($course === null || ($professor !== null && $course->professor_profile_id !== $professor->id)) {
                throw ValidationException::withMessages(['course_id' => __('validation.exists', ['attribute' => __('validation.attributes.course')])]);
            }
        }
        if ($scope === CouponScope::Professor && empty($data['professor_profile_id'])) {
            throw ValidationException::withMessages(['professor_profile_id' => __('validation.required', ['attribute' => __('validation.attributes.professor')])]);
        }

        if ($data['discount_type'] === DiscountType::Fixed->value) {
            $currency = Currency::tryFrom((string) ($data['currency'] ?? ''));
            if ($currency === null || ! in_array($currency->value, $this->settings->enabled_currencies, true)) {
                throw ValidationException::withMessages(['currency' => __('validation.in', ['attribute' => __('validation.attributes.currency')])]);
            }
            if ((int) $data['value'] % $currency->collectionStepMinor() !== 0) {
                throw ValidationException::withMessages(['value' => __('validation.multiple_of', ['attribute' => __('validation.attributes.amount'), 'value' => $currency->collectionStepMinor()])]);
            }
        }
    }
}
