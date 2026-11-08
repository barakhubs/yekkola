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

/** A phone signed in to the account (a live access token on the device). */
function phone(User $user, string $token, bool $signedIn = true): Device
{
    $device = Device::query()->create([
        'user_id' => $user->id, 'platform' => 'android', 'install_id' => (string) Illuminate\Support\Str::ulid(),
        'push_token' => $token, 'registered_at' => now(),
    ]);
    if ($signedIn) {
        $user->createToken('device', ['*'], now()->addDays(30))->accessToken->forceFill(['device_id' => $device->id])->save();
    }

    return $device;
}

it('pushes to every signed-in phone, forgets dead tokens, and caps optional pushes at five a day', function () {
    $user = User::factory()->create();
    phone($user, 'token-a');
    $dead = phone($user, 'invalid-token');
    phone($user, 'token-signed-out', signedIn: false); // signed out: someone else may be using it
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

it('emails professors one digest a day, counted from what happened, with what they still want by email', function () {
    Illuminate\Support\Facades\Storage::fake('local');
    Illuminate\Support\Facades\Storage::fake('public');
    $profile = actingAsProfessor('Marie Kabila');
    $profile->user->forceFill(['email' => 'prof@example.test'])->save();
    $course = publishedCourse($profile, 'Algèbre', ['is_free' => true, 'price_minor' => null, 'currency' => null]);
    $lessonUid = App\Domain\Authoring\Models\Lesson::query()->where('course_version_id', $course->live_version_id)->value('uid');
    foreach (range(1, 2) as $i) {
        $student = User::factory()->create();
        App\Domain\Learning\Models\LessonThread::query()->create(['course_id' => $course->id, 'lesson_uid' => $lessonUid, 'author_id' => $student->id, 'title' => "Question {$i}", 'body' => 'Détails']);
        App\Domain\Learning\Models\Review::query()->create(['course_id' => $course->id, 'user_id' => $student->id, 'rating' => 5]);
    }
    // In-app off doesn't matter (the digest counts the records); email off for reviews does.
    foreach ([['new_question', 'in_app', false], ['new_review', 'email', false]] as [$type, $channel, $on]) {
        App\Domain\Notifications\Models\NotificationPreference::query()->create(['user_id' => $profile->user_id, 'type' => $type, 'channel' => $channel, 'enabled' => $on]);
    }

    Notification::fake();
    $this->artisan('notifications:send-digest')->assertSuccessful();
    $this->artisan('notifications:send-digest')->assertSuccessful(); // same day: nothing more

    Notification::assertSentToTimes($profile->user, ProfessorDigest::class, 1);
    Notification::assertSentTo($profile->user, ProfessorDigest::class, fn (ProfessorDigest $d) => $d->counts === ['new_question' => 2]);
});

it('stops pushing to a phone once another account signs in on it', function () {
    $first = User::factory()->create();
    $device = phone($first, 'shared-phone-token');
    $second = User::factory()->create();

    app(App\Domain\Identity\Actions\RegisterDevice::class)->handle($second, new App\Domain\Identity\Data\DeviceData(
        installId: 'install-shared', platform: App\Domain\Identity\Enums\DevicePlatform::Android, name: null, appVersion: null, pushToken: 'shared-phone-token',
    ));

    expect($device->refresh()->push_token)->toBeNull();
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
});

it('keeps tokens when FCM refuses for a reason of ours (wrong project, bad payload)', function () {
    $pem = (string) file_get_contents(base_path('tests/Fixtures/mux-test-signing-key.pem'));
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'oauth-token', 'expires_in' => 3600]),
        'fcm.googleapis.com/*/messages:send' => Http::sequence()
            ->push(['error' => ['status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.']], 404)
            ->push(['error' => ['status' => 'INVALID_ARGUMENT', 'details' => [['errorCode' => 'INVALID_ARGUMENT'], ['fieldViolations' => [['field' => 'message.data[0].value']]]]]], 400),
    ]);
    $fcm = new FcmPushSender(app(Illuminate\Http\Client\Factory::class), cache()->store(), 'wrong-project', 'push@yekkola.iam.gserviceaccount.com', $pem);

    expect(fn () => $fcm->send('good-token', 'Titre', 'Texte', []))->toThrow(App\Integrations\Push\PushDeliveryFailed::class)
        ->and(fn () => $fcm->send('good-token', 'Titre', 'Texte', []))->toThrow(App\Integrations\Push\PushDeliveryFailed::class);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'messages:send')
        && $request->hasHeader('Authorization', 'Bearer oauth-token')
        && $request['message']['token'] === 'good-token');
});
