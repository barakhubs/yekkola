<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Http\Controllers\Controller;
use App\Integrations\Video\FakeVideoProvider;
use App\Integrations\Video\VideoProvider;
use Illuminate\Http\Response;

/**
 * Stand-in for R2's pre-signed part URLs when the fake video driver is active (local, staging), so the real
 * studio upload flow works end to end: the browser PUTs each part here and gets an `ETag` back, exactly like
 * R2. Nothing is stored. Unavailable with real drivers.
 */
final class FakeVideoUploadController extends Controller
{
    public function __invoke(string $upload, int $part, VideoProvider $videos): Response
    {
        abort_unless($videos instanceof FakeVideoProvider, 404);

        $etag = $videos->receivePart($upload, $part);
        abort_if($etag === null, 404);

        return response()->noContent(200)->header('ETag', $etag);
    }
}
