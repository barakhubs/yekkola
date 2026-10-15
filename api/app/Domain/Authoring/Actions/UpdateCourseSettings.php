<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Actions;

use App\Domain\Authoring\Models\Course;
use App\Domain\Platform\Settings\CommerceSettings;
use App\Domain\Shared\Enums\Currency;
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
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated: category_id, language, level, audience, is_free, price_minor, currency.
     */
    public function handle(Course $course, array $data): Course
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
        if (! is_int($priceMinor) || ! is_string($currencyCode)) {
            throw ValidationException::withMessages(['price_minor' => __('validation.required', ['attribute' => 'price'])]);
        }

        if (! in_array($currencyCode, $this->commerce->enabled_currencies, true)) {
            throw ValidationException::withMessages(['currency' => __('validation.in', ['attribute' => 'currency'])]);
        }

        $currency = Currency::from($currencyCode);
        $limits = $this->commerce->price_limits_minor[$currencyCode] ?? null;

        if ($limits !== null && ($priceMinor < $limits['min'] || $priceMinor > $limits['max'])) {
            throw ValidationException::withMessages(['price_minor' => __('validation.between.numeric', [
                'attribute' => 'price', 'min' => $limits['min'], 'max' => $limits['max'],
            ])]);
        }

        if ($priceMinor % $currency->collectionStepMinor() !== 0) {
            throw ValidationException::withMessages(['price_minor' => __('validation.multiple_of', [
                'attribute' => 'price', 'value' => $currency->collectionStepMinor(),
            ])]);
        }
    }
}
