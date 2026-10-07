<?php

declare(strict_types=1);

namespace App\Integrations\Video;

enum VideoAssetStatus: string
{
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Errored = 'errored';
}
