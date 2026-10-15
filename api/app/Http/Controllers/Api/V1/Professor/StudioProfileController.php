<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Professor;

use App\Domain\Professors\Actions\UpdateProfessorProfile;
use App\Domain\Professors\Enums\LegalDocument;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Domain\Professors\Models\TermsAcceptance;
use App\Http\Controllers\Controller;
use App\Http\Requests\Professor\UpdateProfessorProfileRequest;
use App\Http\Requests\Professor\UploadAvatarRequest;
use App\Http\Resources\ProfessorProfileResource;
use Illuminate\Http\Request;

/**
 * The professor's own profile in the studio (PRD-02 FR-07, FR-10).
 */
final class StudioProfileController extends Controller
{
    public function show(Request $request): ProfessorProfileResource
    {
        return new ProfessorProfileResource($this->profile($request)->load('province'));
    }

    public function update(UpdateProfessorProfileRequest $request, UpdateProfessorProfile $update): ProfessorProfileResource
    {
        return new ProfessorProfileResource($update->handle($this->profile($request), $request->validated())->load('province'));
    }

    /**
     * Upload or replace the profile photo.
     */
    public function avatar(UploadAvatarRequest $request, UpdateProfessorProfile $update): ProfessorProfileResource
    {
        return new ProfessorProfileResource($update->setAvatar($this->profile($request), $request->file('avatar'))->load('province'));
    }

    /**
     * Accept the current professor agreement (required again whenever its version changes).
     */
    public function acceptTerms(Request $request): ProfessorProfileResource
    {
        $request->validate(['version' => ['required', 'string', 'in:'.LegalDocument::ProfessorAgreement->currentVersion()]]);

        $profile = $this->profile($request);

        if (! TermsAcceptance::hasAcceptedCurrent($profile->user_id, LegalDocument::ProfessorAgreement)) {
            TermsAcceptance::record($profile->user_id, LegalDocument::ProfessorAgreement, $request->ip());
        }

        return new ProfessorProfileResource($profile->load('province'));
    }

    private function profile(Request $request): ProfessorProfile
    {
        /** @var ProfessorProfile */
        return $request->attributes->get('professor_profile');
    }
}
