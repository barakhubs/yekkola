<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Authoring\Models\Course;
use App\Domain\Commerce\Enums\BundleStatus;
use App\Domain\Commerce\Models\Bundle;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Platform\Settings\CommerceSettings;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Domain\Shared\Enums\Currency;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a bundle (PRD-05 FR-13, PRD-09 FR-13): at least two paid courses in the bundle's
 * currency, priced below their total and collectable (CDF whole francs). Professors bundle only their own
 * courses; platform bundles may mix professors. Changes apply to new orders only. Audited.
 */
final class SaveBundle
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly CommerceSettings $settings,
        private readonly RecordAudit $recordAudit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated: title, description, price_minor, currency, status, course_ids.
     */
    public function handle(array $data, User $actor, ?Bundle $bundle = null, ?ProfessorProfile $professor = null): Bundle
    {
        $merged = [
            'title' => $data['title'] ?? $bundle?->title,
            'description' => array_key_exists('description', $data) ? $data['description'] : $bundle?->description,
            'price_minor' => (int) ($data['price_minor'] ?? $bundle?->price_minor),
            'currency' => (string) ($data['currency'] ?? $bundle?->currency),
            'status' => $data['status'] ?? $bundle->status->value ?? BundleStatus::Draft->value,
        ];
        /** @var list<string> $courseIds */
        $courseIds = array_values($data['course_ids'] ?? $bundle?->courses()->pluck('courses.id')->all() ?? []);

        // Taking a bundle off sale must always work, even if its courses changed price since.
        $onlyUnpublishing = $bundle !== null && array_keys($data) === ['status'] && $data['status'] !== BundleStatus::Active->value;
        if (! $onlyUnpublishing) {
            $this->assertConsistent($merged, $courseIds, $professor);
        }

        return $this->db->transaction(function () use ($merged, $courseIds, $bundle, $professor, $actor): Bundle {
            $created = $bundle === null;
            $bundle ??= new Bundle([
                'professor_profile_id' => $professor?->id,
                'slug' => $this->slug((string) $merged['title']),
            ]);
            $bundle->fill($merged)->save();
            $bundle->courses()->sync(array_combine($courseIds, array_map(fn (int $i) => ['position' => $i + 1], array_keys($courseIds))));

            $this->recordAudit->handle($created ? 'bundle.created' : 'bundle.updated', $bundle, ['after' => [...$merged, 'course_ids' => $courseIds]], causer: $actor);

            return $bundle;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $courseIds
     */
    private function assertConsistent(array $data, array $courseIds, ?ProfessorProfile $professor): void
    {
        $currency = Currency::tryFrom((string) $data['currency']);
        if ($currency === null || ! in_array($currency->value, $this->settings->enabled_currencies, true)) {
            throw ValidationException::withMessages(['currency' => __('validation.in', ['attribute' => __('validation.attributes.currency')])]);
        }

        $courses = Course::query()->whereIn('id', $courseIds)->get();
        $listed = Course::query()->listed()->whereIn('id', $courseIds)->count();
        $valid = count($courseIds) >= 2 && $courses->count() === count($courseIds) && $courses->every(
            fn (Course $c) => ! $c->is_free && $c->currency === $currency->value && ($professor === null || $c->professor_profile_id === $professor->id)
        ) && ($data['status'] !== BundleStatus::Active->value || $listed === count($courseIds)); // on sale = all published
        if (! $valid) {
            throw ValidationException::withMessages(['course_ids' => __('commerce.bundle.invalid_courses')]);
        }

        $list = (int) $courses->sum('price_minor');
        $price = (int) $data['price_minor'];
        if ($price <= 0 || $price >= $list || $price % $currency->collectionStepMinor() !== 0) {
            throw ValidationException::withMessages(['price_minor' => __('commerce.bundle.invalid_price')]);
        }
    }

    private function slug(string $title): string
    {
        $base = Str::slug($title) ?: 'pack';
        $slug = $base;
        for ($i = 2; Bundle::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
