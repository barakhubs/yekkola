<?php

declare(strict_types=1);

use App\Domain\Catalog\Jobs\RevalidateWebPages;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use App\Integrations\Sms\DemoNumberGuard;
use App\Integrations\Sms\SmsResult;
use App\Integrations\Sms\SmsSender;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Psr\Log\NullLogger;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Http::fake(['web.test/*' => Http::response(['revalidated' => true])]);
    config(['yekkola.web_server_key' => 'server-key', 'yekkola.web_revalidate_secret' => 'revalidate-secret', 'yekkola.web_url' => 'https://web.test']);
});

it('limits public endpoints per visitor, even when our web server renders the page', function () {
    $fromWeb = fn (string $ip) => ['X-Web-Server-Key' => 'server-key', 'X-Client-Ip' => $ip];

    for ($i = 0; $i < 30; $i++) {
        $this->getJson('/api/v1/certificates/YK-0000-0000-0000', $fromWeb('203.0.113.7'))->assertNotFound();
    }
    $this->getJson('/api/v1/certificates/YK-0000-0000-0000', $fromWeb('203.0.113.7'))->assertTooManyRequests();
    $this->getJson('/api/v1/certificates/YK-0000-0000-0000', $fromWeb('203.0.113.8'))->assertNotFound();

    // A forged forwarded IP without the key counts against the caller's own address.
    $this->getJson('/api/v1/certificates/YK-0000-0000-0000', ['X-Client-Ip' => '198.51.100.1'])->assertNotFound();
    for ($i = 0; $i < 30; $i++) {
        $this->getJson('/api/v1/certificates/YK-0000-0000-0000', ['X-Client-Ip' => "198.51.100.{$i}"]);
    }
    $this->getJson('/api/v1/certificates/YK-0000-0000-0000', ['X-Client-Ip' => '198.51.100.99'])->assertTooManyRequests();
});

it('asks the web app to drop cached pages when a public course changes, signed', function () {
    Http::fake(['web.test/*' => Http::response(['revalidated' => true])]);
    $professor = actingAsProfessor('Marie Kabila');
    $course = publishedCourse($professor, 'Algèbre pour l’Exetat');

    Http::assertSent(function (HttpRequest $request) use ($course) {
        $expected = hash_hmac('sha256', $request->header('X-Revalidate-Timestamp')[0].'.'.$request->body(), 'revalidate-secret');

        return $request->url() === 'https://web.test/api/revalidate'
            && $request->header('X-Revalidate-Signature')[0] === $expected
            && in_array("course:{$course->slug}", $request['tags'], true);
    });

    Queue::fake();
    $course->update(['price_minor' => 2_000]);
    Queue::assertPushed(RevalidateWebPages::class, fn ($job) => in_array("course:{$course->slug}", $job->tags, true));

    Queue::fake();
    $course->update(['revenue_share_bps' => 6_000]); // not shown on public pages
    Queue::assertNotPushed(RevalidateWebPages::class);

    // A renamed slug refreshes the old URL too (it now 404s).
    Queue::fake();
    $oldSlug = $course->slug;
    $course->update(['slug' => 'algebre-exetat']);
    Queue::assertPushed(RevalidateWebPages::class, fn ($job) => in_array("course:{$oldSlug}", $job->tags, true) && in_array('course:algebre-exetat', $job->tags, true));

    Queue::fake();
    $professor->update(['headline' => 'Professeure de mathématiques']);
    Queue::assertPushed(RevalidateWebPages::class, fn ($job) => in_array("professor:{$professor->slug}", $job->tags, true));
});

it('never texts demo numbers outside production', function () {
    $inner = new class implements SmsSender
    {
        public int $sent = 0;

        public function send(PhoneNumber $to, string $message): SmsResult
        {
            $this->sent++;

            return new SmsResult('sent');
        }

        public function name(): string
        {
            return 'test';
        }
    };
    $guard = new DemoNumberGuard($inner, '+24399000', new NullLogger);

    expect($guard->send(PhoneNumber::fromString('+243990000012'), 'Code 123456')->providerMessageId)->toBe('demo-suppressed')
        ->and($inner->sent)->toBe(0);
    $guard->send(PhoneNumber::fromString('+243812345678'), 'Code 123456');
    expect($inner->sent)->toBe(1);
});

it('shows a professor rating only from three reviews', function () {
    $professor = actingAsProfessor('Marie Kabila');
    publishedCourse($professor, 'Algèbre pour l’Exetat');
    app('auth')->forgetGuards();

    $professor->forceFill(['rating_avg' => 4.66, 'rating_count' => 2])->save();
    $this->getJson("/api/v1/professors/{$professor->slug}")->assertOk()
        ->assertJsonPath('data.rating', null)
        ->assertJsonMissingPath('data.rating_avg');

    $professor->forceFill(['rating_count' => 3])->save();
    $this->getJson("/api/v1/professors/{$professor->slug}")->assertJsonPath('data.rating', 4.7);
});
