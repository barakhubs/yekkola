<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Protection;

use App\Domain\Identity\Models\User;
use App\Domain\Protection\Actions\RequestDocument;
use App\Domain\Protection\Enums\DocumentStampStatus;
use App\Domain\Protection\Models\DocumentStamp;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Document lessons (PRD-07 FR-12): each student gets their own stamped copy through a short-lived signed
 * link — never the original file.
 */
final class DocumentController extends Controller
{
    /**
     * `ready` with a signed `url` (valid a few minutes), or `processing` (202) while the copy is being made:
     * ask again after `retry_after` seconds.
     */
    public function show(Request $request, string $lesson, RequestDocument $documents): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $stamp = $documents->handle($user, $lesson);

        if ($stamp->status !== DocumentStampStatus::Ready) {
            return response()->json(['data' => ['status' => 'processing', 'retry_after' => 3]], 202);
        }

        $expiresAt = now()->addMinutes((int) config('yekkola.protection.document_link_ttl_minutes'));

        return response()->json(['data' => [
            'status' => 'ready',
            'url' => URL::temporarySignedRoute('documents.download', $expiresAt, ['stamp' => $stamp->id]),
            'expires_at' => $expiresAt->toIso8601String(),
        ]]);
    }

    /**
     * The stamped PDF behind a signed link (no session needed: in-app viewers and downloads open it directly).
     */
    public function download(string $stamp): StreamedResponse
    {
        $model = DocumentStamp::query()->where('status', DocumentStampStatus::Ready)->findOrFail($stamp);

        return Storage::disk((string) config('yekkola.private_disk'))->response((string) $model->path, 'document.pdf', [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
