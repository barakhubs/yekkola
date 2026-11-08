<?php

declare(strict_types=1);

use App\Domain\Finance\Enums\PayoutBatchStatus;
use App\Domain\Finance\Models\PayoutBatch;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\ProfessorDigest;
use App\Integrations\Push\FcmPushSender;
use App\Integrations\Push\PushSender;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

function phone(User $user, string $token): Device
{
    return Device::query()->create([
        'user_id' => $user->id, 'platform' => 'android', 'install_id' => (string) Str::ulid(),
        'push_token' => $token, 'registered_at' => now(),
    ]);
}

it('pushes to every active phone, forgets dead tokens, and caps optional pushes at five a day', function () {
    $user = User::factory()->create();
    phone($user, 'token-a');
    $dead = phone($user, 'invalid-token');
    $notify = app(Notifier::class);

    $notify->user($user, NotificationType::PayoutSent, 'payout_sent', ['amount' => '10 USD', 'last_digits' => '6655'], '/studio/payouts');
    expect(app(PushSender::class)->sent())->toHaveCount(1)
        ->and(app(PushSender::class)->sent()[0]['data'])->toBe(['type' => 'payout_sent', 'link' => '/studio/payouts'])
        ->and($dead->refresh()->push_token)->toBeNull();

    foreach (range(1, 7) as $i) {
        $notify->user($user, NotificationType::NewSale, 'new_sale', ['course' => "Cours {$i}"]);
    }
    expect(app(PushSender::class)->sent())->toHaveCount(6) // 1 critical + 5 optional
        ->and($user->notifications()->count())->toBe(8); // the feed has them all
});

it('emails professors one digest a day with what they still want by email', function () {
    $professor = User::factory()->create(['email' => 'prof@example.test']);
    $noEmail = User::factory()->create();
    $notify = app(Notifier::class);
    foreach ([$professor, $noEmail] as $user) {
        $notify->user($user, NotificationType::NewSale, 'new_sale', ['course' => 'Algèbre']);
        $notify->user($user, NotificationType::NewSale, 'new_sale', ['course' => 'Physique']);
        $notify->user($user, NotificationType::NewReview, 'new_review', ['course' => 'Algèbre', 'rating' => '5']);
    }
    App\Domain\Notifications\Models\NotificationPreference::query()->create(['user_id' => $professor->id, 'type' => 'new_review', 'channel' => 'email', 'enabled' => false]);

    Notification::fake(); // the feed above is real; the digest itself is captured
    $this->artisan('notifications:send-digest')->assertSuccessful();
    $this->artisan('notifications:send-digest')->assertSuccessful(); // same day: nothing more

    Notification::assertSentToTimes($professor, ProfessorDigest::class, 1);
    Notification::assertSentTo($professor, ProfessorDigest::class, fn (ProfessorDigest $d) => $d->counts === ['new_sale' => 2]);
    Notification::assertNotSentTo($noEmail, ProfessorDigest::class);
});

it('routes staff alerts by permission and sends each one once per window', function () {
    $finance = actingAsStaff(Role::Admin, [Permission::FinancePayouts]);
    $moderator = User::factory()->create();
    $moderator->assignRole(Role::Moderator->value);

    $batch = PayoutBatch::query()->create(['status' => PayoutBatchStatus::Processing, 'currency' => 'USD']);
    $batch->forceFill(['status' => PayoutBatchStatus::PartiallyFailed])->save();
    $this->artisan('finance:check-ledger')->assertSuccessful(); // a clean ledger alerts nobody

    $alert = $finance->notifications()->sole();
    expect($alert->data['key'])->toBe('staff_alert.payout_batch')
        ->and($alert->data['app'])->toBe('admin')
        ->and($moderator->notifications()->count())->toBe(0);

    app(App\Domain\Notifications\StaffAlerts::class)->payoutBatchSaved($batch); // repeat within the window
    expect($finance->notifications()->count())->toBe(1);

    // Staff alerts show in the back office's feed, not the web app's.
    $this->getJson('/api/v1/me/notifications/unread-count')->assertJsonPath('data.unread', 0);
    $this->getJson('/api/v1/me/notifications/unread-count?filter[app]=admin')->assertJsonPath('data.unread', 1);
    $this->getJson('/api/v1/me/notifications?filter[app]=admin', ['Accept-Language' => 'fr'])->assertJsonPath('data.0.app', 'admin')
        ->assertJsonPath('data.0.title', 'Lot de versements : échecs');
});

it('sends through FCM with a service-account token and reports dead tokens', function () {
    $pem = (string) file_get_contents(base_path('tests/Fixtures/mux-test-signing-key.pem')); // a throwaway test key
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'oauth-token', 'expires_in' => 3600]),
        'fcm.googleapis.com/*/messages:send' => Http::sequence()
            ->push(['name' => 'projects/yekkola/messages/1'])
            ->push(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
    ]);
    $fcm = new FcmPushSender(app(Illuminate\Http\Client\Factory::class), cache()->store(), 'yekkola', 'push@yekkola.iam.gserviceaccount.com', $pem);

    expect($fcm->send('good-token', 'Titre', 'Texte', ['link' => '/orders']))->toBeTrue()
        ->and($fcm->send('old-token', 'Titre', 'Texte', []))->toBeFalse();
    Http::assertSent(fn ($request) => str_contains($request->url(), 'messages:send')
        && $request->hasHeader('Authorization', 'Bearer oauth-token')
        && $request['message']['token'] === 'good-token');
});
