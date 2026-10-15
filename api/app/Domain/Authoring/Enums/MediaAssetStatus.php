<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Enums;

/**
 * Processing state of a video/audio asset on the provider.
 */
enum MediaAssetStatus: string
{
    case WaitingUpload = 'waiting_upload';
    case Processing = 'processing';
    case Ready = 'ready';
    case Errored = 'errored';
    case Cancelled = 'cancelled';
}
