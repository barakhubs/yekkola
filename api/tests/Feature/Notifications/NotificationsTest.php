<?php

declare(strict_types=1);

use App\Domain\Authoring\Models\Lesson;
use App\Domain\Identity\Actions\ChangeUserStatus;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Notifier;
use App\Integrations\Sms\SmsSender;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/** @return list<string> */
function smsTo(string $e164): array
{
    return array_values(array_map(fn (array $m) => $m['message'], array_filter(app(SmsSender::class)->sent(), fn (array $m) => $m['to'] === $e164)));
}

function signInAs(User $user): void
{
    app('auth')->forgetGuards();
    Sanctum::actingAs($user);
}

it('tells a student the professor answered, in their language, unless they turned it off', function () {
    Storage::fake('local');
    Storage::fake('public');
    $professor = actingAsProfessor('Marie Kabila');
    $course = publishedCourse($professor, 'Algèbre', ['is_free' => true, 'price_minor' => null, 'currency' => null]);
    $lesson = Lesson::query()->where('course_version_id', $course->live_version_id)->orderBy('position')->firstOrFail();
    $student = User::factory()->create(['locale' => 'en']);
    expect($professor->user->notifications()->sole()->data['key'])->toBe('version_decided.approved');
    $professor->user->unreadNotifications->markAsRead();

    signInAs($student);
    $this->postJson("/api/v1/courses/{$course->id}/enroll")->assertOk();
    $first = $this->postJson("/api/v1/lessons/{$lesson->uid}/threads", ['title' => 'Comment factoriser ?', 'body' => 'Exemple 2.'])->json('data.id');
    $second = $this->postJson("/api/v1/lessons/{$lesson->uid}/threads", ['title' => 'Et le discriminant ?', 'body' => 'Exemple 3.'])->json('data.id');

    signInAs($professor->user); // the professor heard of both questions
    $this->getJson('/api/v1/me/notifications/unread-count')->assertOk()->assertJsonPath('data.unread', 2);
    $this->postJson("/api/v1/threads/{$first}/replies", ['body' => 'Voir la leçon 3.'])->assertOk();

    signInAs($student);
    $this->getJson('/api/v1/me/notifications', ['Accept-Language' => 'en'])->assertOk()
        ->assertJsonPath('data.0.title', 'The professor answered')
        ->assertJsonPath('data.0.link', "/learn/{$course->id}/{$lesson->uid}");

    $this->putJson('/api/v1/me/notification-preferences', ['preferences' => [['type' => 'question_answered', 'channel' => 'in_app', 'enabled' => false]]])
        ->assertOk()->assertJsonFragment(['type' => 'question_answered', 'channels' => ['in_app' => false, 'push' => true]]);
    // Critical events can't be turned off (BR-02), and SMS is never a user choice.
    $this->putJson('/api/v1/me/notification-preferences', ['preferences' => [['type' => 'payout_sent', 'channel' => 'in_app', 'enabled' => false]]])->assertUnprocessable();
    $this->putJson('/api/v1/me/notification-preferences', ['preferences' => [['type' => 'new_sale', 'channel' => 'sms', 'enabled' => false]]])->assertUnprocessable();

    signInAs($professor->user);
    $this->postJson("/api/v1/threads/{$second}/replies", ['body' => 'Voir la leçon 4.'])->assertOk();

    signInAs($student);
    $this->getJson('/api/v1/me/notifications/unread-count')->assertJsonPath('data.unread', 1);
    $this->postJson('/api/v1/me/notifications/read')->assertOk()->assertJsonPath('data.unread', 0);
});

it('uses SMS and email only where the catalogue says, and always for critical events', function () {
    $staff = actingAsStaff(Role::Admin);
    $withEmail = User::factory()->create(['email' => 'eleve@example.test']);
    $phoneOnly = User::factory()->create();

    app(ChangeUserStatus::class)->handle($withEmail, UserStatus::Suspended, 'Fraude', $staff);
    expect(smsTo($withEmail->phone_e164))->toHaveCount(1)
        ->and($withEmail->notifications()->count())->toBe(1);
    expect((new App\Domain\Notifications\PlatformNotification(NotificationType::AccountStatus, 'account_status.suspended'))->via($withEmail))
        ->toBe(['database', 'mail', App\Domain\Notifications\Channels\SmsChannel::class])
        ->and((new App\Domain\Notifications\PlatformNotification(NotificationType::AccountStatus, 'account_status.suspended'))->via($phoneOnly))
        ->not->toContain('mail');

    // Non-critical events never use SMS; every SMS text fits one message.
    app(Notifier::class)->user($phoneOnly, NotificationType::NewSale, 'new_sale', ['course' => 'Algèbre']);
    expect(smsTo($phoneOnly->phone_e164))->toBe([]);
    foreach (['fr', 'en'] as $locale) {
        foreach (smsTo($withEmail->phone_e164) as $text) {
            expect(mb_strlen($text))->toBeLessThanOrEqual(160);
        }
        expect(mb_strlen((string) __('notifications.payout_failed.sms', ['amount' => '1 250 000 CDF'], $locale)))->toBeLessThanOrEqual(160);
    }
});

it('sends a sale one SMS, to a parent paying from their own number, and the refund decision to whoever asked', function () {
    Storage::fake('local');
    Storage::fake('public');
    $professor = actingAsProfessor('Marie Kabila');
    $course = publishedCourse($professor, 'Algèbre pour l’Exetat', ['price_minor' => 2_500_000, 'currency' => 'CDF']);
    $parent = User::factory()->create();
    signInAs($parent);

    $orderId = $this->postJson('/api/v1/orders', ['course_ids' => [$course->id], 'beneficiary_phone' => '+243991112233', 'payer_msisdn' => '+243827776655'], ['Idempotency-Key' => 'o-1'])
        ->assertCreated()->json('data.id');
    $paymentId = $this->postJson("/api/v1/orders/{$orderId}/payments", ['rail' => 'airtel'], ['Idempotency-Key' => 'p-1'])->assertCreated()->json('data.id');
    sendPaymentWebhook($paymentId);

    $child = User::query()->where('phone_e164', '+243991112233')->sole();
    expect($parent->notifications()->sole()->data['key'])->toBe('payment_succeeded.receipt')
        ->and($child->notifications()->sole()->data['key'])->toBe('payment_succeeded.gift')
        ->and($professor->user->notifications()->where('data->key', 'new_sale')->count())->toBe(1)
        ->and(smsTo($parent->phone_e164))->toBe([])
        ->and(smsTo($child->phone_e164))->toBe([]);
    $payerSms = smsTo('+243827776655');
    expect($payerSms)->toHaveCount(1)
        ->and(mb_strlen($payerSms[0]))->toBeLessThanOrEqual(160)
        ->and($payerSms[0])->toMatch('/^[\x20-\x7E]+$/'); // GSM-7 safe: no no-break spaces in the amount

    // The student asks for the refund; both the student and the parent who paid hear the decision.
    signInAs($child);
    $itemId = App\Domain\Commerce\Models\Order::query()->with('items')->findOrFail($orderId)->items->sole()->id;
    $refundId = $this->postJson("/api/v1/orders/{$orderId}/refund-requests", ['order_item_id' => $itemId, 'reason' => 'Pas mon niveau.'])->assertCreated()->json('data.id');
    actingAsStaff(Role::Admin, [App\Domain\Identity\Enums\Permission::FinanceRefunds]);
    $this->postJson("/api/v1/admin/refunds/{$refundId}/reject", ['note' => 'Cours déjà suivi.'])->assertOk();

    expect($child->notifications()->where('data->key', 'refund_decided.rejected')->count())->toBe(1)
        ->and($parent->notifications()->where('data->key', 'refund_decided.rejected')->count())->toBe(1);
});

it('never forwards a staff note to the author it warns', function () {
    Storage::fake('local');
    Storage::fake('public');
    $professor = actingAsProfessor('Marie Kabila');
    $course = publishedCourse($professor, 'Algèbre pour l’Exetat', ['is_free' => true, 'price_minor' => null, 'currency' => null]);
    $reporter = User::factory()->create();
    signInAs($reporter);
    $this->postJson('/api/v1/reports', ['type' => 'course', 'id' => $course->id, 'reason' => 'copyright'])->assertCreated();

    actingAsStaff(Role::Moderator);
    $report = App\Domain\Moderation\Models\Report::query()->sole();
    $this->postJson("/api/v1/admin/reports/{$report->id}/resolve", ['action' => 'warn', 'note' => 'Signalé par Jean (+243 81 000 0000)'])->assertOk();

    $warning = $professor->user->notifications()->where('data->type', 'warning')->sole();
    expect($warning->data['key'])->toBe('warning.course')
        ->and(json_encode($warning->data))->not->toContain('243');
});
