<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Category;
use App\Domain\Identity\Enums\Role;
use App\Domain\Platform\Models\Activity;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Category::query()->delete(); // start from an empty tree
    $this->admin = actingAsStaff(Role::Admin);
});

function newCategory(array $body): Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/admin/categories', $body + ['is_visible' => true]);
}

it('builds a two-level tree in both languages, ordered per level', function () {
    $sciences = newCategory(['name' => ['fr' => 'Sciences', 'en' => 'Science']])->assertCreated()
        ->assertJsonPath('data.slug', 'sciences')->assertJsonPath('data.name', ['fr' => 'Sciences', 'en' => 'Science'])->json('data.id');
    $langues = newCategory(['name' => ['fr' => 'Langues', 'en' => 'Languages']])->json('data.id');
    $physique = newCategory(['parent_id' => $sciences, 'name' => ['fr' => 'Physique', 'en' => 'Physics']])->json('data.id');
    newCategory(['parent_id' => $sciences, 'name' => ['fr' => 'Sciences', 'en' => 'Science']])->assertJsonPath('data.slug', 'sciences-2');

    // Two levels at most.
    newCategory(['parent_id' => $physique, 'name' => ['fr' => 'Optique', 'en' => 'Optics']])->assertUnprocessable()
        ->assertJsonPath('code', 'category.invalid_parent');
    $this->patchJson("/api/v1/admin/categories/{$sciences}", ['parent_id' => $langues, 'name' => ['fr' => 'Sciences', 'en' => 'Science'], 'is_visible' => true])
        ->assertUnprocessable(); // it has sub-categories

    $this->putJson('/api/v1/admin/categories/order', ['ids' => [$langues, $sciences]])->assertOk()
        ->assertJsonPath('data.0.id', $langues)
        ->assertJsonPath('data.1.id', $sciences)
        ->assertJsonPath('data.2.parent_id', $sciences);
    $this->putJson('/api/v1/admin/categories/order', ['ids' => [$langues]])->assertUnprocessable()
        ->assertJsonPath('code', 'category.order_mismatch');

    expect(Activity::query()->where('event', 'like', 'category.%')->count())->toBe(5); // 4 created + 1 reordered
});

it('deletes only what nothing uses; hides otherwise', function () {
    $sciences = newCategory(['name' => ['fr' => 'Sciences', 'en' => 'Science']])->json('data.id');
    $physique = newCategory(['parent_id' => $sciences, 'name' => ['fr' => 'Physique', 'en' => 'Physics']])->json('data.id');

    $this->deleteJson("/api/v1/admin/categories/{$sciences}")->assertConflict()->assertJsonPath('code', 'category.in_use');
    $this->patchJson("/api/v1/admin/categories/{$sciences}", ['name' => ['fr' => 'Sciences', 'en' => 'Science'], 'is_visible' => false])
        ->assertOk()->assertJsonPath('data.is_visible', false);
    $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(0, 'data'); // hidden from the public tree

    $this->deleteJson("/api/v1/admin/categories/{$physique}")->assertNoContent();
    $this->deleteJson("/api/v1/admin/categories/{$sciences}")->assertNoContent();
});

it('is for catalogue managers', function () {
    Sanctum::actingAs(actingAsStaff(Role::Moderator));

    $this->getJson('/api/v1/admin/categories')->assertForbidden();
});
