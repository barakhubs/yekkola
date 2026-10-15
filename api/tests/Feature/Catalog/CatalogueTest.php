<?php

declare(strict_types=1);

use App\Domain\Authoring\Enums\CourseStatus;
use App\Domain\Catalog\Jobs\ReindexCourses;
use App\Domain\Catalog\Models\Category;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Events\UserAnonymizing;
use App\Domain\Platform\Models\Activity;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->professor = actingAsProfessor();
    $this->maths = publishedCourse($this->professor, 'Algèbre pour l’Exetat');
    $this->sciences = publishedCourse($this->professor, 'Physique de base', [
        'category_id' => Category::query()->where('slug', 'exetat-sciences')->value('id'),
        'is_free' => true, 'price_minor' => null, 'currency' => null,
        'language' => 'en', 'audience' => ['exetat'], 'enrollment_count' => 40,
    ]);
    $this->app['auth']->forgetGuards();
});

function slugs(Illuminate\Testing\TestResponse $response): array
{
    return collect($response->assertOk()->json('data'))->pluck('slug')->all();
}

it('lists only published courses from active professors', function () {
    $draft = courseReadyForReview($this->professor, 'Chimie en préparation');
    $unpublished = publishedCourse($this->professor, 'Histoire du Congo', ['status' => CourseStatus::Unpublished]);
    $this->app['auth']->forgetGuards();

    expect(slugs($this->getJson('/api/v1/courses')))->toBe([$this->sciences->slug, $this->maths->slug]);
    $this->getJson("/api/v1/courses/{$draft->slug}")->assertNotFound();
    $this->getJson("/api/v1/courses/{$unpublished->slug}")->assertNotFound();

    $this->professor->update(['status' => 'suspended']);
    $this->getJson('/api/v1/courses')->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/courses/{$this->maths->slug}")->assertNotFound();
});

it('filters and sorts the catalogue', function (array $query, array $expected) {
    $expected = array_map(fn (string $key) => $this->{$key}->slug, $expected);

    expect(slugs($this->getJson('/api/v1/courses?'.http_build_query($query))))->toBe($expected);
})->with([
    'free' => [['price' => 'free'], ['sciences']],
    'paid' => [['price' => 'paid'], ['maths']],
    'parent category includes subcategories' => [['category' => 'exetat'], ['sciences']],
    'category' => [['category' => 'mathematiques'], ['maths']],
    'unknown category' => [['category' => 'nope'], []],
    'language' => [['language' => 'fr'], ['maths']],
    'audience' => [['audience' => 'exetat'], ['sciences']],
    'currency' => [['currency' => 'USD'], ['maths']],
    'price ascending: free first' => [['sort' => 'price_asc'], ['sciences', 'maths']],
    'price descending' => [['sort' => 'price_desc'], ['maths', 'sciences']],
]);

it('rejects unknown filter values', function () {
    $this->getJson('/api/v1/courses?sort=cheapest')->assertUnprocessable();
    $this->getJson('/api/v1/courses?audience=maternelle')->assertUnprocessable();
});

it('searches titles and professor names', function () {
    expect(slugs($this->getJson('/api/v1/courses?q=Physique')))->toBe([$this->sciences->slug])
        ->and(slugs($this->getJson('/api/v1/courses?q=Kabila&sort=newest')))->toHaveCount(2)
        ->and(slugs($this->getJson('/api/v1/courses?q=Physique&price=paid')))->toBe([]);
});

it('falls back to the database when the search service is down', function () {
    config(['scout.driver' => 'meilisearch', 'scout.meilisearch.host' => 'http://127.0.0.1:9']);

    expect(slugs($this->getJson('/api/v1/courses?q=physique')))->toBe([$this->sciences->slug]);
});

it('shows the course page with its curriculum outline and download size', function () {
    $response = $this->getJson("/api/v1/courses/{$this->maths->slug}")->assertOk();

    $response->assertJsonPath('data.title', 'Algèbre pour l’Exetat')
        ->assertJsonPath('data.professor.display_name', 'Marie Kabila')
        ->assertJsonPath('data.price', ['amount_minor' => 1_500, 'currency' => 'USD'])
        ->assertJsonPath('data.rating', null)
        ->assertJsonPath('data.lesson_count', 3)
        ->assertJsonPath('data.sections.0.lessons.0.is_preview', true)
        ->assertJsonPath('data.sections.0.lessons.2.type', 'video')
        ->assertJsonPath('data.sections.0.lessons.2.download_bytes', 150_000_000) // 20 min at 1,000 kbps
        ->assertJsonMissingPath('data.sections.0.lessons.1.quiz');

    expect($response->json('data.download_bytes'))->toBeGreaterThan(150_000_000)
        ->and(json_encode($response->json()))->not->toContain('is_correct');
});

it('shows a rating only from three reviews', function () {
    $this->maths->update(['rating_avg' => 4.66, 'rating_count' => 2]);
    $this->getJson("/api/v1/courses/{$this->maths->slug}")->assertJsonPath('data.rating', null);

    $this->maths->update(['rating_count' => 3]);
    $this->getJson("/api/v1/courses/{$this->maths->slug}")->assertJsonPath('data.rating', 4.7);
});

it('returns the visible category tree with course counts', function () {
    Category::query()->where('slug', 'exetat-lettres')->update(['is_visible' => false]);

    $tree = collect($this->getJson('/api/v1/categories', ['Accept-Language' => 'en'])->assertOk()->json('data'))->keyBy('slug');

    expect($tree['exetat']['name'])->toBe('State Exam (Exetat)')
        ->and($tree['exetat']['course_count'])->toBe(1)
        ->and(collect($tree['exetat']['children'])->pluck('slug')->all())->toBe(['exetat-sciences', 'exetat-commerciale'])
        ->and($tree['mathematiques']['course_count'])->toBe(1);
});

it('shows a professor page with their listed courses', function () {
    $this->getJson("/api/v1/professors/{$this->professor->slug}")
        ->assertOk()
        ->assertJsonCount(2, 'data.courses')
        ->assertJsonPath('data.courses.0.slug', $this->sciences->slug);
});

it('lets admins order the featured rail', function () {
    actingAsStaff(Role::Moderator);
    $this->putJson('/api/v1/admin/featured-courses', ['course_ids' => [$this->maths->id]])->assertForbidden();

    $admin = actingAsStaff(Role::Admin);
    $this->putJson('/api/v1/admin/featured-courses', ['course_ids' => [$this->maths->id, $this->sciences->id]])
        ->assertOk()
        ->assertJsonPath('data.0.slug', $this->maths->slug);

    $draft = courseReadyForReview($this->professor, 'Cours non publié');
    Sanctum::actingAs($admin);
    $this->putJson('/api/v1/admin/featured-courses', ['course_ids' => [$draft->id]])->assertUnprocessable();

    $rails = $this->getJson('/api/v1/featured')->assertOk();
    expect(collect($rails->json('data.featured'))->pluck('slug')->all())->toBe([$this->maths->slug, $this->sciences->slug])
        ->and(collect($rails->json('data.free'))->pluck('slug')->all())->toBe([$this->sciences->slug])
        ->and($rails->json('data.popular.0.slug'))->toBe($this->sciences->slug)
        ->and(Activity::query()->where('event', 'catalog.featured_updated')->sole()->causer_id)->toBe($admin->id);
});

it('keeps a wishlist of listed courses', function () {
    $this->getJson('/api/v1/me/wishlist')->assertUnauthorized();

    $student = App\Domain\Identity\Models\User::factory()->create();
    Sanctum::actingAs($student);

    $this->postJson('/api/v1/me/wishlist', ['course_id' => $this->maths->id])->assertNoContent();
    $this->postJson('/api/v1/me/wishlist', ['course_id' => $this->maths->id])->assertNoContent();
    $this->travel(1)->minutes();
    $this->postJson('/api/v1/me/wishlist', ['course_id' => $this->sciences->id])->assertNoContent();

    expect(slugs($this->getJson('/api/v1/me/wishlist')))->toBe([$this->sciences->slug, $this->maths->slug]);

    $this->sciences->update(['status' => CourseStatus::Unpublished]);
    expect(slugs($this->getJson('/api/v1/me/wishlist')))->toBe([$this->maths->slug]);
    $this->postJson('/api/v1/me/wishlist', ['course_id' => $this->sciences->id])->assertNotFound();

    $this->deleteJson("/api/v1/me/wishlist/{$this->maths->id}")->assertNoContent();
    $this->getJson('/api/v1/me/wishlist')->assertJsonCount(0, 'data');

    $this->postJson('/api/v1/me/wishlist', ['course_id' => $this->maths->id])->assertNoContent();
    event(new UserAnonymizing($student));
    $this->getJson('/api/v1/me/wishlist')->assertJsonCount(0, 'data');
});

it('reindexes a professor\'s courses when their status or name changes', function () {
    Queue::fake();

    $this->professor->update(['headline' => 'Enseignante']);
    Queue::assertNotPushed(ReindexCourses::class);

    $this->professor->update(['status' => 'suspended']);
    Queue::assertPushed(ReindexCourses::class, fn (ReindexCourses $job) => $job->value === $this->professor->id);
});

it('hides courses of banned or deleted professor accounts', function () {
    $this->professor->user->forceFill(['status' => 'banned'])->save();
    $this->getJson('/api/v1/courses')->assertJsonCount(0, 'data');

    $this->professor->user->forceFill(['status' => 'active'])->save();
    $this->professor->user->delete();
    $this->getJson("/api/v1/courses/{$this->maths->slug}")->assertNotFound();
});

it('sorts by newest and leaves hidden subcategories out of a parent', function () {
    $this->maths->update(['published_at' => now()->subDay()]);
    expect(slugs($this->getJson('/api/v1/courses?sort=newest')))->toBe([$this->sciences->slug, $this->maths->slug]);

    Category::query()->where('slug', 'exetat-sciences')->update(['is_visible' => false]);
    expect(slugs($this->getJson('/api/v1/courses?category=exetat')))->toBe([]);
});

it('drops featured courses that are no longer listed', function () {
    $this->maths->update(['featured_position' => 1, 'status' => CourseStatus::Unpublished]);
    $this->sciences->update(['featured_position' => 2]);

    expect(collect($this->getJson('/api/v1/featured')->json('data.featured'))->pluck('slug')->all())->toBe([$this->sciences->slug]);
});

it('skips the search service for a minute after it fails', function () {
    config(['scout.driver' => 'meilisearch', 'scout.meilisearch.host' => 'http://127.0.0.1:9']);
    $this->getJson('/api/v1/courses?q=physique')->assertOk();

    expect(cache()->has('catalogue:search-down'))->toBeTrue();
});
