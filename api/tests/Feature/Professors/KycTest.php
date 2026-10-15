<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\Role;
use App\Domain\Platform\Models\Activity;
use App\Domain\Professors\Enums\KycStatus;
use App\Domain\Professors\Models\KycSubmission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => Storage::fake('local'));

function idFiles(bool $withBack = true): array
{
    return array_filter([
        'front' => UploadedFile::fake()->image('front.jpg'),
        'back' => $withBack ? UploadedFile::fake()->image('back.jpg') : null,
        'selfie' => UploadedFile::fake()->image('selfie.jpg'),
    ]);
}

it('accepts an ID check on the private disk', function () {
    $profile = actingAsProfessor();

    $this->post('/api/v1/professor/kyc', ['document_type' => 'national_id', ...idFiles()], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonMissingPath('data.files');

    $submission = KycSubmission::query()->sole();
    expect($profile->refresh()->kyc_status)->toBe(KycStatus::Pending)
        ->and($submission->getFirstMedia('front')->disk)->toBe('local')
        ->and($submission->getFirstMedia('selfie'))->not->toBeNull();
});

it('needs the back side except for passports', function () {
    actingAsProfessor();

    $this->post('/api/v1/professor/kyc', ['document_type' => 'voter_card', ...idFiles(false)], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'kyc.back_required');

    $this->post('/api/v1/professor/kyc', ['document_type' => 'passport', ...idFiles(false)], ['Accept' => 'application/json'])
        ->assertCreated();
});

it('allows one pending check at a time', function () {
    actingAsProfessor();
    $this->post('/api/v1/professor/kyc', ['document_type' => 'passport', ...idFiles(false)], ['Accept' => 'application/json'])->assertCreated();

    $this->post('/api/v1/professor/kyc', ['document_type' => 'passport', ...idFiles(false)], ['Accept' => 'application/json'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'kyc.already_pending');
});

it('lets staff with the KYC permission verify, granting the badge', function () {
    $profile = actingAsProfessor();
    $this->post('/api/v1/professor/kyc', ['document_type' => 'passport', ...idFiles(false)], ['Accept' => 'application/json']);
    $submission = KycSubmission::query()->sole();

    actingAsStaff(Role::Moderator);
    $this->postJson("/api/v1/admin/kyc-submissions/{$submission->id}/verify")->assertForbidden();

    actingAsStaff(Role::Admin);
    $this->getJson('/api/v1/admin/kyc-submissions?filter[status]=pending')
        ->assertOk()
        ->assertJsonPath('data.0.files.front', route('admin.kyc-submissions.file', [$submission->id, 'front']));

    $this->postJson("/api/v1/admin/kyc-submissions/{$submission->id}/verify")->assertOk()->assertJsonPath('data.status', 'verified');

    expect($profile->refresh()->kyc_status)->toBe(KycStatus::Verified)
        ->and($profile->verified_at)->not->toBeNull();
    $this->getJson("/api/v1/professors/{$profile->slug}")->assertJsonPath('data.is_verified', true);
});

it('lets a rejected professor resubmit', function () {
    $profile = actingAsProfessor();
    $this->post('/api/v1/professor/kyc', ['document_type' => 'passport', ...idFiles(false)], ['Accept' => 'application/json']);
    $submission = KycSubmission::query()->sole();

    actingAsStaff(Role::Moderator, [Permission::KycView, Permission::KycReview]);
    $this->postJson("/api/v1/admin/kyc-submissions/{$submission->id}/reject")->assertUnprocessable();
    $this->postJson("/api/v1/admin/kyc-submissions/{$submission->id}/reject", ['note' => 'Photo floue.'])->assertOk();
    $this->postJson("/api/v1/admin/kyc-submissions/{$submission->id}/verify")->assertStatus(409)->assertJsonPath('code', 'kyc.not_pending');

    expect($profile->refresh()->kyc_status)->toBe(KycStatus::Rejected);

    Sanctum::actingAs($profile->user);
    $this->getJson('/api/v1/professor/kyc')->assertJsonPath('data.rejection_reason', 'Photo floue.');
    $this->post('/api/v1/professor/kyc', ['document_type' => 'passport', ...idFiles(false)], ['Accept' => 'application/json'])->assertCreated();
});

it('streams ID images only to KYC staff, auditing every view', function () {
    actingAsProfessor();
    $this->post('/api/v1/professor/kyc', ['document_type' => 'national_id', ...idFiles()], ['Accept' => 'application/json']);
    $submission = KycSubmission::query()->sole();

    actingAsStaff(Role::Moderator);
    $this->get("/api/v1/admin/kyc-submissions/{$submission->id}/files/front")->assertForbidden();

    actingAsStaff(Role::Admin);
    $this->get("/api/v1/admin/kyc-submissions/{$submission->id}/files/front")->assertOk();
    $this->get("/api/v1/admin/kyc-submissions/{$submission->id}/files/passport-photo")->assertNotFound();

    expect(Activity::query()->where('event', 'kyc.file_viewed')->count())->toBe(1);
});
