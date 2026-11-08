<?php

declare(strict_types=1);

namespace App\Integrations\Push;

use Firebase\JWT\JWT;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Firebase Cloud Messaging, HTTP v1 API. Authenticates with a service account: a signed JWT is exchanged for an
 * OAuth token, cached until shortly before it expires. Keys live only in API env vars (FCM_*).
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
                'data' => $data,
                'android' => ['priority' => 'high'],
            ]]);

        if ($response->successful()) {
            return true;
        }
        if ($this->isDeadToken($response)) {
            return false;
        }

        throw new PushDeliveryFailed("FCM error {$response->status()}");
    }

    public function name(): string
    {
        return 'fcm';
    }

    /** UNREGISTERED (404) or a malformed token (400 INVALID_ARGUMENT on the token): stop sending to it. */
    private function isDeadToken(Response $response): bool
    {
        $code = (string) $response->json('error.details.0.errorCode', $response->json('error.status', ''));

        return $response->status() === 404 || in_array($code, ['UNREGISTERED', 'INVALID_ARGUMENT'], true);
    }

    private function accessToken(): string
    {
        /** @var string */
        return $this->cache->remember('fcm:access-token', now()->addMinutes(50), function (): string {
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
