<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Actions;

use App\Domain\Authoring\Models\Course;
use App\Domain\Platform\Settings\CommerceSettings;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\MoneyFormatter;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

/**
 * Course-level settings (PRD-03 FR-09, FR-15, FR-18): category, language, level, audience, and pricing.
 * These are not version content — they apply immediately, going forward only (existing enrollments and
 * order snapshots are untouched).
 */
final class UpdateCourseSettings
{
    public function __construct(
        private readonly CommerceSettings $commerce,
        private readonly FreeCourseLimits $freeLimits,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated: category_id, language, level, audience, is_free, price_minor, currency.
     */
    public function handle(Course $course, array $data): Course
    {
        return $this->db->transaction(fn (): Course => $this->apply($course, $data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function apply(Course $course, array $data): Course
    {
        $isFree = (bool) ($data['is_free'] ?? $course->is_free);

        if ($isFree) {
            if (! $course->is_free) {
                $this->freeLimits->assertCanMakeFree($course);
            }
            $data['price_minor'] = null;
            $data['currency'] = null;
        } elseif (array_key_exists('price_minor', $data) || array_key_exists('currency', $data) || $course->is_free) {
            $this->assertValidPrice($data['price_minor'] ?? $course->price_minor, $data['currency'] ?? $course->currency);
        }

        $course->fill($data)->save();

        return $course;
    }

    private function assertValidPrice(mixed $priceMinor, mixed $currencyCode): void
    {
        if (! is_numeric($priceMinor) || ! is_string($currencyCode)) {
            throw ValidationException::withMessages(['price_minor' => __('validation.required', ['attribute' => __('validation.attributes.price')])]);
        }

        $priceMinor = (int) $priceMinor;

        if (! in_array($currencyCode, $this->commerce->enabled_currencies, true)) {
            throw ValidationException::withMessages(['currency' => __('validation.in', ['attribute' => __('validation.attributes.currency')])]);
        }

        $currency = Currency::from($currencyCode);
        $limits = $this->commerce->price_limits_minor[$currencyCode] ?? null;

        if ($limits !== null && ($priceMinor < $limits['min'] || $priceMinor > $limits['max'])) {
            throw ValidationException::withMessages(['price_minor' => __('validation.between.numeric', [
                'attribute' => __('validation.attributes.price'),
                'min' => MoneyFormatter::format(Money::of($limits['min'], $currency), app()->getLocale()),
                'max' => MoneyFormatter::format(Money::of($limits['max'], $currency), app()->getLocale()),
            ])]);
        }

        if ($priceMinor % $currency->collectionStepMinor() !== 0) {
            throw ValidationException::withMessages(['price_minor' => __('validation.multiple_of', [
                'attribute' => __('validation.attributes.price'),
                'value' => MoneyFormatter::format(Money::of($currency->collectionStepMinor(), $currency), app()->getLocale()),
            ])]);
        }
    }
}
