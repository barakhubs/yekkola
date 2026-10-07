<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Platform\Models\Activity;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('records the target model, the acting user, the reason, and the request IP', function () {
    $admin = User::factory()->create();
    $target = User::factory()->create();

    Route::middleware(['api', 'auth:sanctum'])->post('/api/v1/_test/suspend/{user}', function (User $user, RecordAudit $audit) {
        $audit->handle('user.suspended', $user, ['reason' => 'Fraude signalée']);

        return response()->noContent();
    });

    Sanctum::actingAs($admin);
    $this->withServerVariables(['REMOTE_ADDR' => '41.243.10.20'])
        ->postJson("/api/v1/_test/suspend/{$target->id}", [], ['User-Agent' => 'YekkolaAdmin/1.0'])
        ->assertNoContent();

    $entry = Activity::query()->sole();

    expect(Str::isUlid($entry->id))->toBeTrue()
        ->and($entry->event)->toBe('user.suspended')
        ->and($entry->subject->is($target))->toBeTrue()
        ->and($entry->causer->is($admin))->toBeTrue()
        ->and($entry->properties->get('reason'))->toBe('Fraude signalée')
        ->and($entry->properties->get('context'))->toBe(['ip' => '41.243.10.20', 'user_agent' => 'YekkolaAdmin/1.0']);
});

it('accepts an explicit causer and works without an authenticated user', function () {
    $staff = User::factory()->create();

    $withCauser = app(RecordAudit::class)->handle('payout.batch_built', properties: ['count' => 3], causer: $staff);
    $system = app(RecordAudit::class)->handle('ledger.integrity_checked', properties: ['ok' => true]);

    expect($withCauser->causer->is($staff))->toBeTrue()
        ->and($system->causer_id)->toBeNull()
        ->and($system->subject)->toBeNull();
});
