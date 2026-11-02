<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Role;
use App\Domain\Platform\Models\Activity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    actingAsStaff(Role::Admin);
});

it('replaces a brand image, deletes the old file and audits the change', function () {
    $this->getJson('/api/v1/branding')->assertOk()->assertJsonPath('data.logo_url', null);

    $first = $this->post('/api/v1/admin/settings/branding/logo', ['file' => UploadedFile::fake()->image('logo.png', 400, 120)], ['Accept' => 'application/json'])
        ->assertOk()->json('data.logo_url');
    $this->post('/api/v1/admin/settings/branding/logo', ['file' => UploadedFile::fake()->image('logo.png', 400, 120)], ['Accept' => 'application/json'])->assertOk();

    $files = Storage::disk('public')->files('branding');
    expect($files)->toHaveCount(1)
        ->and($first)->not->toContain($files[0])
        ->and(Activity::query()->where('event', 'settings.updated')->count())->toBe(2);
    $this->getJson('/api/v1/branding')->assertOk()->assertJsonPath('data.logo_url', Storage::disk('public')->url($files[0]));

    $this->deleteJson('/api/v1/admin/settings/branding/logo')->assertOk()->assertJsonPath('data.logo_url', null);
    expect(Storage::disk('public')->files('branding'))->toBe([]);
});

it('checks each image against its own rules, for settings managers only', function () {
    // A favicon must be a square PNG.
    $this->post('/api/v1/admin/settings/branding/favicon', ['file' => UploadedFile::fake()->image('icon.png', 64, 32)], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->post('/api/v1/admin/settings/branding/favicon', ['file' => UploadedFile::fake()->image('icon.jpg', 64, 64)], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->post('/api/v1/admin/settings/branding/favicon', ['file' => UploadedFile::fake()->image('icon.png', 64, 64)], ['Accept' => 'application/json'])->assertOk();
    $this->post('/api/v1/admin/settings/branding/share_image', ['file' => UploadedFile::fake()->image('share.jpg', 800, 400)], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->post('/api/v1/admin/settings/branding/unknown', ['file' => UploadedFile::fake()->image('x.png', 64, 64)], ['Accept' => 'application/json'])->assertNotFound();

    app('auth')->forgetGuards();
    actingAsStaff(Role::Moderator);
    $this->post('/api/v1/admin/settings/branding/logo', ['file' => UploadedFile::fake()->image('logo.png', 400, 120)], ['Accept' => 'application/json'])->assertForbidden();
});
