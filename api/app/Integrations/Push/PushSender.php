<?php

declare(strict_types=1);

namespace App\Integrations\Push;

/**
 * Sends push notifications to a device token (PRD-10 FR-04). FCM in production (`FcmPushSender`); `LogPushSender`
 * in development and tests.
 */
interface PushSender
{
    /**
     * @param  array<string, string>  $data  Deep link and type for the app (`link`, `type`).
     * @return bool false when the token is no longer valid (app removed, token rotated): the caller forgets it.
     *
     * @throws PushDeliveryFailed on a provider error worth retrying
     */
    public function send(string $token, string $title, string $body, array $data): bool;

    public function name(): string;
}
