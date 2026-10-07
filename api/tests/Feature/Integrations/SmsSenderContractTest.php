<?php

declare(strict_types=1);

use App\Domain\Shared\ValueObjects\PhoneNumber;
use App\Integrations\Sms\LogSmsSender;
use App\Integrations\Sms\SmsSender;

/*
| Contract every SmsSender must satisfy. Add real drivers to the dataset when they're integrated
| (using Http::fake() for the provider API).
*/

dataset('sms senders', [
    'log' => fn (): SmsSender => new LogSmsSender(logger()),
]);

it('returns a provider message id for an accepted message', function (Closure $make) {
    $result = $make()->send(PhoneNumber::fromString('+243812345678'), 'Votre code Yekkola : 123456');

    expect($result->providerMessageId)->not->toBeEmpty();
})->with('sms senders');

it('gives each message its own id', function (Closure $make) {
    $sender = $make();
    $to = PhoneNumber::fromString('+243812345678');

    expect($sender->send($to, 'a')->providerMessageId)->not->toBe($sender->send($to, 'b')->providerMessageId);
})->with('sms senders');

it('is the configured driver in non-production environments', function () {
    expect(app(SmsSender::class))->toBeInstanceOf(LogSmsSender::class);
});

it('records what the log driver sent, for tests', function () {
    $sender = app(SmsSender::class);
    $sender->send(PhoneNumber::fromString('0812345678'), 'Votre code : 654321');

    expect($sender->sent())->toHaveCount(1)
        ->and($sender->sent()[0])->toMatchArray(['to' => '+243812345678', 'message' => 'Votre code : 654321']);
});
