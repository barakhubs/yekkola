<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Learning;

use App\Domain\Authoring\Models\Course;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\Certificate;
use App\Http\Controllers\Controller;
use App\Http\Resources\Learning\CertificateResource;
use App\Http\Resources\Learning\CertificateVerificationResource;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/** My certificates and their PDFs, and the public verification by serial (PRD-06 FR-10, FR-11; PRD-04 FR-09). */
final class CertificateController extends Controller
{
    /**
     * My certificates, newest first, revoked ones included (marked).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $certificates = Certificate::query()->where('user_id', $this->user($request)->id)->with('course')
            ->orderByDesc('issued_at')
            ->paginate($this->perPage($request));

        return CertificateResource::collection($certificates);
    }

    /**
     * The certificate as a PDF in my language, with a QR code to its verification page. Valid ones only.
     */
    public function pdf(Request $request, string $certificate): Response
    {
        $user = $this->user($request);
        $model = Certificate::query()->valid()->where('user_id', $user->id)->findOrFail($certificate);
        $locale = $user->locale;

        $qr = (new Writer(new ImageRenderer(new RendererStyle(160, 1), new SvgImageBackEnd)))->writeString($model->verificationUrl());

        return Pdf::loadView('pdf.certificate', [
            'certificate' => $model,
            'locale' => $locale,
            'qr' => 'data:image/svg+xml;base64,'.base64_encode($qr),
        ])
            ->setPaper('a4', 'landscape')
            ->download(__('certificate.filename', [], $locale).'-'.$model->serial.'.pdf');
    }

    /**
     * Public check of a certificate by serial: valid or revoked, with the names as issued.
     */
    public function verify(string $serial): CertificateVerificationResource
    {
        $certificate = Certificate::query()->where('serial', strtoupper($serial))->firstOrFail();
        $slug = Course::query()->listed()->whereKey($certificate->course_id)->value('slug');

        return new CertificateVerificationResource($certificate, is_string($slug) ? $slug : null);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
