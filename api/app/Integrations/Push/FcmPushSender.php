<?php

declare(strict_types=1);

namespace App\Integrations\Push;

use Firebase\JWT\JWT;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Firebase Cloud Messaging, HTTP v1 API. Authenticates with a service account: a signed JWT is exchanged for an
 * OAuth token, cached until shortly before it expires (and dropped if FCM rejects it). Keys live only in API env
 * vars (FCM_*). Only errors that name the token mark it dead; anything else (a wrong project, a bad payload) is a
 * failure of ours and leaves tokens alone.
 */
final class FcmPushSender implements PushSender
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly Cache $cache,
        private readonly string $projectId,
        private readonly string $clientEmail,
        private readonly string $privateKey,
    ) {}

    public function send(string $token, string $title, string $body, array $data): bool
    {
        $response = $this->http->withToken($this->accessToken())->acceptJson()->timeout(10)
            ->post("https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send", ['message' => [
                'token' => $token,
                'notification' => ['title' => $title, 'body' => $body],
                'data' => (object) $data, // a map, even when empty
                'android' => ['priority' => 'high'],
            ]]);

        if ($response->successful()) {
            return true;
        }
        if (in_array($response->status(), [401, 403], true) && ! $this->hasErrorCode($response, 'SENDER_ID_MISMATCH')) {
            $this->cache->forget($this->cacheKey()); // rotated or revoked key: fetch a new token next time
        }
        if ($this->isDeadToken($response)) {
            return false;
        }

        throw new PushDeliveryFailed("FCM error {$response->status()} ".(string) $response->json('error.status', ''));
    }

    public function name(): string
    {
        return 'fcm';
    }

    /** The app was removed or the token belongs to another project, or the request was refused because of the token. */
    private function isDeadToken(Response $response): bool
    {
        if ($this->hasErrorCode($response, 'UNREGISTERED') || $this->hasErrorCode($response, 'SENDER_ID_MISMATCH')) {
            return true;
        }

        return $this->hasErrorCode($response, 'INVALID_ARGUMENT')
            && str_contains(strtolower((string) json_encode($response->json('error.details', []))), 'message.token');
    }

    private function hasErrorCode(Response $response, string $code): bool
    {
        $details = $response->json('error.details', []);

        return is_array($details) && collect($details)->contains(fn ($d) => is_array($d) && ($d['errorCode'] ?? null) === $code);
    }

    private function cacheKey(): string
    {
        return 'fcm:access-token:'.sha1($this->projectId.'|'.$this->clientEmail);
    }

    private function accessToken(): string
    {
        /** @var string */
        return $this->cache->remember($this->cacheKey(), now()->addMinutes(50), function (): string {
            $now = time();
            $assertion = JWT::encode([
                'iss' => $this->clientEmail,
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ], str_replace('\n', "\n", $this->privateKey), 'RS256');

            $response = $this->http->asForm()->timeout(10)->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);
            if (! $response->successful() || ! is_string($response->json('access_token'))) {
                throw new PushDeliveryFailed("FCM auth error {$response->status()}");
            }

            return (string) $response->json('access_token');
        });
    }
}
