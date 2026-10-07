<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\DataExportStatus;
use App\Domain\Identity\Models\DataExport;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Activity;
use Illuminate\Support\Facades\Storage;

// Deletion (FR-11)

it('records a deletion request and lets the user cancel it', function () {
    $token = signInMobile()->json('data.token');

    freshAuth();
    $this->deleteJson('/api/v1/me', [], bearer($token))->assertOk()->assertJsonPath('data.deletion_requested_at', fn ($v) => $v !== null);

    freshAuth();
    $this->deleteJson('/api/v1/me/deletion', [], bearer($token))->assertOk()->assertJsonPath('data.deletion_requested_at', null);
});

it('never anonymises banned accounts (no ban evasion by deletion)', function () {
    $token = signInMobile()->json('data.token');
    freshAuth();
    $this->deleteJson('/api/v1/me', [], bearer($token))->assertOk();
    userByPhone('+243812345678')->forceFill(['status' => App\Domain\Identity\Enums\UserStatus::Banned])->save();

    $this->travel(15)->days();
    $this->artisan('identity:purge-deleted-accounts')->assertSuccessful();

    expect(userByPhone('+243812345678')->trashed())->toBeFalse();
});

it('anonymises accounts after the 14-day grace period and frees the number', function () {
    $token = signInMobile()->json('data.token');
    freshAuth();
    $this->deleteJson('/api/v1/me', [], bearer($token))->assertOk();
    $user = userByPhone('+243812345678');

    $this->travel(13)->days();
    $this->artisan('identity:purge-deleted-accounts')->assertSuccessful();
    expect(userByPhone('+243812345678')->trashed())->toBeFalse();

    $this->travel(2)->days();
    $this->artisan('identity:purge-deleted-accounts')->assertSuccessful();

    $erased = User::withTrashed()->find($user->id);
    expect($erased->trashed())->toBeTrue()
        ->and($erased->phone_e164)->toStartWith('deleted:')
        ->and($erased->name)->toBeNull()
        ->and($erased->tokens()->count())->toBe(0)
        ->and(Device::query()->active()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'user.anonymized')->exists())->toBeTrue()
        ->and(App\Domain\Identity\Models\OtpChallenge::query()->count())->toBe(0);

    freshAuth();
    $this->getJson('/api/v1/me', bearer($token))->assertUnauthorized();

    // The number can sign up again as a brand-new account.
    $this->travel(1)->minutes();
    signInMobile()->assertOk()->assertJsonPath('data.is_new_user', true);
});

// Export (FR-12)

it('builds a personal-data export the user can download', function () {
    Storage::fake('local');
    $token = signInMobile()->json('data.token');

    freshAuth();
    $this->postJson('/api/v1/me/export', [], bearer($token))->assertAccepted();

    freshAuth();
    $this->getJson('/api/v1/me/export', bearer($token))
        ->assertOk()
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.download_url', route('me.export.download'));

    freshAuth();
    $download = $this->get('/api/v1/me/export/download', bearer($token))->assertOk();
    $json = json_decode($download->streamedContent(), true);

    expect($json['profile']['phone'])->toBe('+243812345678')
        ->and($json['devices'])->toHaveCount(1)
        ->and($json['roles'])->toContain('student');
});

it('allows one export in progress at a time', function () {
    $token = signInMobile()->json('data.token');
    DataExport::query()->create(['user_id' => userByPhone('+243812345678')->id, 'status' => DataExportStatus::Pending]);

    freshAuth();
    $this->postJson('/api/v1/me/export', [], bearer($token))->assertStatus(409)->assertJsonPath('code', 'export.in_progress');
});

it('allows one export per day', function () {
    Storage::fake('local');
    $token = signInMobile()->json('data.token');

    freshAuth();
    $this->postJson('/api/v1/me/export', [], bearer($token))->assertAccepted();
    freshAuth();
    $this->postJson('/api/v1/me/export', [], bearer($token))->assertStatus(429)->assertJsonPath('code', 'export.rate_limited');

    $this->travel(25)->hours();
    freshAuth();
    $this->postJson('/api/v1/me/export', [], bearer($token))->assertAccepted();
});

it('prunes expired export files', function () {
    Storage::fake('local');
    $token = signInMobile()->json('data.token');
    freshAuth();
    $this->postJson('/api/v1/me/export', [], bearer($token));
    $path = DataExport::query()->sole()->path;

    $this->travel(9)->days();
    $this->artisan('model:prune', ['--model' => [DataExport::class]])->assertSuccessful();

    expect(DataExport::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

it('refuses downloads before an export is ready or after it expires', function () {
    Storage::fake('local');
    $token = signInMobile()->json('data.token');

    freshAuth();
    $this->getJson('/api/v1/me/export', bearer($token))->assertNotFound()->assertJsonPath('code', 'export.not_found');
    freshAuth();
    $this->get('/api/v1/me/export/download', bearer($token))->assertStatus(409)->assertJsonPath('code', 'export.not_ready');

    freshAuth();
    $this->postJson('/api/v1/me/export', [], bearer($token));
    $this->travel(8)->days();

    freshAuth();
    $this->get('/api/v1/me/export/download', bearer($token))->assertStatus(409);
});
