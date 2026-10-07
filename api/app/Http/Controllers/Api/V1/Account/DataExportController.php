<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Domain\Identity\Actions\RequestDataExport;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Models\DataExport;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Resources\DataExportResource;
use Illuminate\Contracts\Filesystem\Factory as Storage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Personal-data export (PRD-01 FR-12).
 */
final class DataExportController extends Controller
{
    /**
     * Request a new export (built in the background).
     */
    public function store(Request $request, RequestDataExport $requestExport): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new DataExportResource($requestExport->handle($user)))->response()->setStatusCode(202);
    }

    /**
     * Status of the latest export.
     */
    public function show(Request $request): DataExportResource
    {
        return new DataExportResource($this->latest($request) ?? throw IdentityException::exportNotReady());
    }

    /**
     * Download the latest ready export.
     */
    public function download(Request $request, Storage $storage): StreamedResponse
    {
        $export = $this->latest($request);

        if ($export === null || ! $export->isDownloadable()) {
            throw IdentityException::exportNotReady();
        }

        return $storage->disk(DataExport::DISK)->download((string) $export->path, 'yekkola-mes-donnees.json');
    }

    private function latest(Request $request): ?DataExport
    {
        /** @var User $user */
        $user = $request->user();

        return DataExport::query()->where('user_id', $user->id)->latest()->first();
    }
}
