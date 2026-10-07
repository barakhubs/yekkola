<?php

declare(strict_types=1);

namespace App\Domain\Platform\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Content protection and devices (PRD-07).
 */
final class ProtectionSettings extends Settings
{
    /** Mobile devices per account that can hold offline downloads. */
    public int $registered_device_limit;

    public int $concurrent_web_streams;

    /** How long offline downloads play before the app must re-validate online. */
    public int $offline_license_days;

    public int $playback_token_ttl_minutes;

    public static function group(): string
    {
        return 'protection';
    }
}
