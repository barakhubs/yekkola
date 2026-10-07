<?php

declare(strict_types=1);

namespace App\Integrations\Video;

enum VideoEventType: string
{
    /** Upload finished and an asset was created (processing starts). */
    case AssetCreated = 'asset_created';
    case AssetReady = 'asset_ready';
    case AssetErrored = 'asset_errored';
    case UploadCancelled = 'upload_cancelled';
    /** Anything we don't act on — acknowledge and ignore. */
    case Other = 'other';
}
