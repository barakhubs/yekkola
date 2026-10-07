<?php

declare(strict_types=1);

use App\Domain\Shared\ValueObjects\PhoneNumber;
use App\Integrations\Sms\LogSmsSender;
use App\Integrations\Sms\SmsDeliveryFailed;
use App\Integrations\Sms\SmsSender;
use Psr\Log\AbstractLogger;

/*
| Contract every SmsSender must satisfy. Add real drivers to the dataset when they're integrated
| (using Http::fake() for the provider API), including how to make the provider reject a message.
*/

dataset('sms senders', [
    'log' => [fn (): SmsSender => new LogSmsSender(logger()), '+243812340000'],
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

it('throws SmsDeliveryFailed when the provider rejects a message', function (Closure $make, string $rejectedNumber) {
    $make()->send(PhoneNumber::fromString($rejectedNumber), 'Votre code : 123456');
})->with('sms senders')->throws(SmsDeliveryFailed::class);

// Log-driver behaviour

it('is the configured driver in non-production environments', function () {
    expect(app(SmsSender::class))->toBeInstanceOf(LogSmsSender::class);
});

it('records what it sent, for tests', function () {
    $sender = app(SmsSender::class);
    $sender->send(PhoneNumber::fromString('0812345678'), 'Votre code : 654321');

    expect($sender->sent())->toHaveCount(1)
        ->and($sender->sent()[0])->toMatchArray(['to' => '+243812345678', 'message' => 'Votre code : 654321']);
});

it('masks the phone number in the log', function () {
    $logger = new class extends AbstractLogger
    {
        /** @var list<array<string, mixed>> */
        public array $records = [];

        public function log($level, Stringable|string $message, array $context = []): void
        {
            $this->records[] = $context;
        }
    };

    (new LogSmsSender($logger))->send(PhoneNumber::fromString('+243812345678'), 'Votre code : 111222');

    expect($logger->records[0]['to'])->toBe('+243 8•• ••• 678');
});
