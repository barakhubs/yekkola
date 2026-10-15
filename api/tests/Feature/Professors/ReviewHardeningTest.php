<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\AnonymizeUser;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Professors\Actions\ReviewApplication;
use App\Domain\Professors\Actions\ReviewKyc;
use App\Domain\Professors\Enums\ApplicationStatus;
use App\Domain\Professors\Enums\KycDocumentType;
use App\Domain\Professors\Enums\KycStatus;
use App\Domain\Professors\Enums\KycSubmissionStatus;
use App\Domain\Professors\Exceptions\ProfessorException;
use App\Domain\Professors\Models\KycSubmission;
use App\Domain\Professors\Models\ProfessorApplication;
use App\Domain\Professors\Models\ProfessorProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
});

it('lets only one of two concurrent decisions win (stale models)', function () {
    $staff = actingAsStaff();
    $application = ProfessorApplication::query()->create([
        'user_id' => User::factory()->create()->id,
        'status' => ApplicationStatus::Submitted,
        'full_name' => 'Paul Tshibanda',
    ]);
    $staleCopy = ProfessorApplication::query()->find($application->id);

    app(ReviewApplication::class)->approve($application, $staff, null);

    expect(fn () => app(ReviewApplication::class)->reject($staleCopy, $staff, 'Trop tard.'))
        ->toThrow(ProfessorException::class)
        ->and($application->fresh()->status)->toBe(ApplicationStatus::Approved)
        ->and(ProfessorProfile::query()->count())->toBe(1);

    $this->postJson("/api/v1/admin/professor-applications/{$application->id}/approve")->assertStatus(409);
});

it('never downgrades a verified professor when another pending check is rejected', function () {
    $profile = actingAsProfessor();
    $staff = actingAsStaff(Role::Admin);
    $first = KycSubmission::query()->create(['professor_profile_id' => $profile->id, 'document_type' => KycDocumentType::Passport, 'status' => KycSubmissionStatus::Pending]);

    app(ReviewKyc::class)->verify($first, $staff);
    $second = KycSubmission::query()->create(['professor_profile_id' => $profile->id, 'document_type' => KycDocumentType::Passport, 'status' => KycSubmissionStatus::Rejected]);
    $second->forceFill(['status' => KycSubmissionStatus::Pending])->save();
    app(ReviewKyc::class)->reject($second, $staff, 'Doublon.');

    expect($profile->refresh()->kyc_status)->toBe(KycStatus::Verified);
});

it('enforces one pending check per professor in the database', function () {
    $profile = actingAsProfessor();
    KycSubmission::query()->create(['professor_profile_id' => $profile->id, 'document_type' => KycDocumentType::Passport, 'status' => KycSubmissionStatus::Pending]);

    KycSubmission::query()->create(['professor_profile_id' => $profile->id, 'document_type' => KycDocumentType::Passport, 'status' => KycSubmissionStatus::Pending]);
})->throws(Illuminate\Database\UniqueConstraintViolationException::class);

it('requires kyc.review, not just kyc.view, to decide on identity checks', function () {
    $profile = actingAsProfessor();
    $submission = KycSubmission::query()->create(['professor_profile_id' => $profile->id, 'document_type' => KycDocumentType::Passport, 'status' => KycSubmissionStatus::Pending]);

    actingAsStaff(Role::Moderator, [Permission::KycView]);
    $this->getJson("/api/v1/admin/kyc-submissions/{$submission->id}")->assertOk();
    $this->postJson("/api/v1/admin/kyc-submissions/{$submission->id}/verify")->assertForbidden();
});

it('erases professor data and files when the account is anonymised, and keeps admin lists working', function () {
    $profile = actingAsProfessor();
    $profile->update(['bio' => 'Biographie', 'payout_rail' => 'airtel', 'payout_msisdn' => '+243972345678', 'payout_name' => 'Marie']);
    $profile->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaCollection('avatar');
    $submission = $profile->kycSubmissions()->create(['document_type' => KycDocumentType::Passport, 'status' => KycSubmissionStatus::Verified]);
    $submission->addMedia(UploadedFile::fake()->image('front.jpg'))->toMediaCollection('front');
    $application = ProfessorApplication::query()->create(['user_id' => $profile->user_id, 'status' => ApplicationStatus::Approved, 'full_name' => 'Marie Kabila', 'motivation' => 'x', 'submitted_at' => now()]);
    $slug = $profile->slug;

    app(AnonymizeUser::class)->handle($profile->user);

    $profile->refresh();
    expect($profile->display_name)->toBe('—')
        ->and($profile->bio)->toBeNull()
        ->and($profile->payout_msisdn)->toBeNull()
        ->and($application->fresh()->full_name)->toBeNull()
        ->and(Media::query()->count())->toBe(0);

    $this->getJson("/api/v1/professors/{$slug}")->assertNotFound();

    actingAsStaff(Role::Admin);
    $this->getJson('/api/v1/admin/professors')->assertOk()->assertJsonPath('data.0.phone', null);
    $this->getJson('/api/v1/admin/professor-applications')->assertOk()->assertJsonPath('data.0.applicant.anonymised', true);
});

it('hides the public page of a banned account', function () {
    $profile = actingAsProfessor();
    $profile->user->forceFill(['status' => UserStatus::Banned])->save();

    $this->getJson("/api/v1/professors/{$profile->slug}")->assertNotFound();
});

it('re-encodes avatars so photo metadata is not published', function () {
    actingAsProfessor();
    $jpegWithExif = UploadedFile::fake()->image('me.jpg', 300, 300);
    file_put_contents($jpegWithExif->getRealPath(), str_replace("\xFF\xD8", "\xFF\xD8\xFF\xE1\x00\x10Exif\x00\x00GPSDATA!", file_get_contents($jpegWithExif->getRealPath())));

    $this->post('/api/v1/professor/profile/avatar', ['avatar' => $jpegWithExif], ['Accept' => 'application/json'])->assertOk();

    $media = ProfessorProfile::query()->sole()->getFirstMedia('avatar');
    expect(Storage::disk('public')->get($media->getPathRelativeToRoot()))->not->toContain('GPSDATA');
});

it('streams private files with no-store and sandbox headers', function () {
    $profile = actingAsProfessor();
    $submission = $profile->kycSubmissions()->create(['document_type' => KycDocumentType::Passport, 'status' => KycSubmissionStatus::Pending]);
    $submission->addMedia(UploadedFile::fake()->image('front.jpg'))->toMediaCollection('front');

    actingAsStaff(Role::Admin);
    $response = $this->get("/api/v1/admin/kyc-submissions/{$submission->id}/files/front")->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox');
});

it('does not expire an info request the applicant is still working on', function () {
    $user = User::factory()->create();
    $application = ProfessorApplication::query()->create([
        'user_id' => $user->id,
        'status' => ApplicationStatus::NeedsInfo,
        'info_requested_at' => now(),
    ]);

    $this->travel(29)->days();
    $application->update(['motivation' => 'Je complète mon dossier.']);
    $this->travel(2)->days();
    $this->artisan('professors:expire-stale-applications')->assertSuccessful();

    expect($application->fresh()->status)->toBe(ApplicationStatus::NeedsInfo);
});

it('shows applicants the note only for rejections', function () {
    $user = User::factory()->create();
    Laravel\Sanctum\Sanctum::actingAs($user);
    ProfessorApplication::query()->create(['user_id' => $user->id, 'status' => ApplicationStatus::Approved, 'decision_note' => 'Note interne.', 'decided_at' => now()]);

    $this->getJson('/api/v1/professor/application')->assertJsonPath('data.decision_note', null);
});

it('uses a dedicated SMS text for payout-method codes', function () {
    actingAsProfessor();
    $this->postJson('/api/v1/professor/payout-method/otp')->assertAccepted();

    $sent = app(App\Integrations\Sms\SmsSender::class)->sent();
    expect(end($sent)['message'])->toContain('CHANGE the number');
});
