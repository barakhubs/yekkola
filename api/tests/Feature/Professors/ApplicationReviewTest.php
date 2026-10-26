<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Activity;
use App\Domain\Professors\Enums\ApplicationStatus;
use App\Domain\Professors\Models\ProfessorApplication;
use App\Domain\Professors\Models\ProfessorProfile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    $this->applicant = User::factory()->create(['name' => 'Grace Ilunga']);
    $this->application = ProfessorApplication::query()->create([
        'user_id' => $this->applicant->id,
        'status' => ApplicationStatus::Submitted,
        'full_name' => 'Grace Ilunga',
        'subjects' => ['Français'],
        'levels' => ['secondary'],
        'years_experience' => 5,
        'motivation' => 'Enseigner partout au Congo.',
        'submitted_at' => now(),
    ]);
});

it('shows the queue to moderators but not to students', function () {
    ProfessorApplication::query()->create(['user_id' => User::factory()->create()->id, 'status' => ApplicationStatus::Draft]);

    Laravel\Sanctum\Sanctum::actingAs(tap(User::factory()->create())->assignRole(Role::Student->value));
    $this->getJson('/api/v1/admin/professor-applications')->assertForbidden();

    actingAsStaff(Role::Moderator);
    $this->getJson('/api/v1/admin/professor-applications')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.applicant.phone', fn (string $p) => str_contains($p, '•'));
});

it('filters the queue by status', function () {
    actingAsStaff();

    $this->getJson('/api/v1/admin/professor-applications?filter[status]=approved')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/admin/professor-applications?filter[status]=submitted')->assertOk()->assertJsonCount(1, 'data');
    $this->application->update(['status' => ApplicationStatus::UnderReview]);
    $this->getJson('/api/v1/admin/professor-applications?filter[status]=submitted,under_review')->assertOk()->assertJsonCount(1, 'data');
});

it('approves: creates the profile and professor role, audited', function () {
    $staff = actingAsStaff();

    $this->postJson("/api/v1/admin/professor-applications/{$this->application->id}/start-review")
        ->assertOk()->assertJsonPath('data.status', 'under_review');

    $this->postJson("/api/v1/admin/professor-applications/{$this->application->id}/approve", ['note' => 'Excellent dossier.'])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'grace-ilunga')
        ->assertJsonPath('data.kyc_status', 'none')
        ->assertJsonPath('data.is_payable', false);

    expect($this->applicant->fresh()->hasRole(Role::Professor->value))->toBeTrue()
        ->and(ProfessorProfile::query()->sole()->display_name)->toBe('Grace Ilunga')
        ->and($this->application->fresh()->status)->toBe(ApplicationStatus::Approved)
        ->and(Activity::query()->where('event', 'professor_application.approved')->sole()->causer_id)->toBe($staff->id);
});

it('makes slugs unique', function () {
    ProfessorProfile::query()->create(['user_id' => User::factory()->create()->id, 'slug' => 'grace-ilunga', 'display_name' => 'Grace Ilunga']);
    actingAsStaff();

    $this->postJson("/api/v1/admin/professor-applications/{$this->application->id}/approve")
        ->assertCreated()
        ->assertJsonPath('data.slug', 'grace-ilunga-2');
});

it('requires a note to reject or request info', function (string $action, ApplicationStatus $result) {
    actingAsStaff();

    $this->postJson("/api/v1/admin/professor-applications/{$this->application->id}/{$action}")
        ->assertUnprocessable()
        ->assertJsonStructure(['errors' => ['note']]);

    $this->postJson("/api/v1/admin/professor-applications/{$this->application->id}/{$action}", ['note' => 'Merci de préciser vos diplômes.'])
        ->assertOk();

    expect($this->application->fresh()->status)->toBe($result);
})->with([
    'reject' => ['reject', ApplicationStatus::Rejected],
    'request info' => ['request-info', ApplicationStatus::NeedsInfo],
]);

it('refuses decisions on already decided applications', function () {
    $this->application->update(['status' => ApplicationStatus::Rejected]);
    actingAsStaff();

    $this->postJson("/api/v1/admin/professor-applications/{$this->application->id}/approve")
        ->assertStatus(409)
        ->assertJsonPath('code', 'application.invalid_transition');
});

it('forbids staff from deciding on their own application', function () {
    $staff = actingAsStaff();
    $this->application->update(['user_id' => $staff->id]);

    $this->postJson("/api/v1/admin/professor-applications/{$this->application->id}/approve")
        ->assertForbidden()
        ->assertJsonPath('code', 'application.own_application');
});

it('tells staff which decisions apply', function () {
    $staff = actingAsStaff();
    $can = fn () => $this->getJson("/api/v1/admin/professor-applications/{$this->application->id}")->json('data.can');

    expect($can())->toBe(['start_review' => true, 'decide' => true]);

    $this->postJson("/api/v1/admin/professor-applications/{$this->application->id}/start-review")->assertOk();
    expect($can())->toBe(['start_review' => false, 'decide' => true]);

    $this->postJson("/api/v1/admin/professor-applications/{$this->application->id}/reject", ['note' => 'Dossier incomplet.'])->assertOk();
    expect($can())->toBe(['start_review' => false, 'decide' => false]);

    $this->application->update(['status' => ApplicationStatus::Submitted, 'user_id' => $staff->id]);
    expect($can())->toBe(['start_review' => false, 'decide' => false]); // never their own
});

it('hides drafts from staff', function () {
    $this->application->update(['status' => ApplicationStatus::Draft]);
    actingAsStaff();

    $this->getJson("/api/v1/admin/professor-applications/{$this->application->id}")->assertNotFound();
});

it('streams credential files to staff and audits each view', function () {
    $media = $this->application->addMedia(fakePdf())->toMediaCollection('credentials');
    actingAsStaff();

    $this->get("/api/v1/admin/professor-applications/{$this->application->id}/credentials/{$media->uuid}")->assertOk();

    expect(Activity::query()->where('event', 'professor_application.credential_viewed')->count())->toBe(1);
});
