<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Professor;

use App\Domain\Professors\Actions\SubmitKyc;
use App\Domain\Professors\Exceptions\ProfessorException;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Professor\SubmitKycRequest;
use App\Http\Resources\KycSubmissionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Identity verification (PRD-02 FR-08). Professors see the status of their checks, never the files.
 */
final class KycController extends Controller
{
    /**
     * Latest identity check.
     */
    public function show(Request $request): KycSubmissionResource
    {
        $submission = $this->profile($request)->kycSubmissions()->latest()->first()
            ?? throw ProfessorException::kycNotFound();

        return new KycSubmissionResource($submission);
    }

    /**
     * Submit ID front, back (not for passports), and a selfie.
     */
    public function store(SubmitKycRequest $request, SubmitKyc $submitKyc): JsonResponse
    {
        $submission = $submitKyc->handle(
            $this->profile($request),
            $request->documentType(),
            $request->file('front'),
            $request->file('back'),
            $request->file('selfie'),
        );

        return (new KycSubmissionResource($submission))->response()->setStatusCode(201);
    }

    private function profile(Request $request): ProfessorProfile
    {
        /** @var ProfessorProfile */
        return $request->attributes->get('professor_profile');
    }
}
