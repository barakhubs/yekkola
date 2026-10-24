<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Tells the web app to drop cached public pages (PRD-04 FR-11, FR-12): a published, changed, unpublished or
 * taken-down course shows up — or disappears — on the next visit instead of after the cache lifetime. Signed
 * with the shared secret; skipped when none is configured (local without the web app).
 */
final class RevalidateWebPages implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 30, 120, 600];

    /**
     * @param  list<string>  $tags  Cache tags the web app uses: `courses`, `course:{slug}`, `professor:{slug}`, `categories`.
     */
    public function __construct(public readonly array $tags)
    {
        $this->afterCommit();
    }

    public function handle(HttpFactory $http): void
    {
        $secret = (string) config('yekkola.web_revalidate_secret');
        if ($secret === '' || $this->tags === []) {
            return;
        }

        $body = (string) json_encode(['tags' => array_values(array_unique($this->tags))]);
        $timestamp = (string) now()->getTimestamp();

        $http->timeout(10)
            ->withHeaders([
                'X-Revalidate-Timestamp' => $timestamp,
                'X-Revalidate-Signature' => hash_hmac('sha256', "{$timestamp}.{$body}", $secret),
            ])
            ->withBody($body, 'application/json')
            ->post(rtrim((string) config('yekkola.web_url'), '/').'/api/revalidate')
            ->throw();
    }
}
