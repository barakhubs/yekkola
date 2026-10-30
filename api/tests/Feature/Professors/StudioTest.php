<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Professors\Models\ProfessorProfile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

it('keeps the studio for approved professors', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/professor/profile')->assertForbidden()->assertJsonPath('code', 'professor.required');
});

it('shows the professor their own profile with onboarding status', function () {
    actingAsProfessor();

    $this->getJson('/api/v1/professor/profile')
        ->assertOk()
        ->assertJsonPath('data.slug', 'marie-kabila')
        ->assertJsonPath('data.terms.accepted', true)
        ->assertJsonPath('data.onboarding', ['profile_complete' => false, 'kyc_verified' => false, 'payout_method_set' => false, 'first_course' => false]);
});

it('ticks the first course once one is sent for review', function () {
    Storage::fake('local');
    Storage::fake('public');
    $professor = actingAsProfessor();
    $course = courseReadyForReview($professor);
    $this->getJson('/api/v1/professor/profile')->assertJsonPath('data.onboarding.first_course', false);

    $this->postJson("/api/v1/professor/courses/{$course->id}/submit")->assertOk();

    $this->getJson('/api/v1/professor/profile')->assertJsonPath('data.onboarding.first_course', true);
});

it('updates the profile and lets the slug change only once', function () {
    actingAsProfessor();

    $this->patchJson('/api/v1/professor/profile', [
        'headline' => 'Professeure de mathématiques',
        'bio' => 'Quinze ans au lycée.',
        'links' => [['label' => 'Site', 'url' => 'https://example.cd']],
        'slug' => 'prof-marie',
    ])->assertOk()->assertJsonPath('data.slug', 'prof-marie')->assertJsonPath('data.can_change_slug', false);

    $this->patchJson('/api/v1/professor/profile', ['slug' => 'marie-maths'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'professor.slug_already_changed');
});

it('refuses a slug that is taken or malformed', function (string $slug, int $status) {
    ProfessorProfile::query()->create(['user_id' => User::factory()->create()->id, 'slug' => 'taken-slug', 'display_name' => 'X']);
    actingAsProfessor();

    $this->patchJson('/api/v1/professor/profile', ['slug' => $slug])->assertStatus($status);
})->with([
    'taken' => ['taken-slug', 422],
    'uppercase' => ['Marie', 422],
    'spaces' => ['marie k', 422],
]);

it('stores the avatar publicly', function () {
    Storage::fake('public');
    actingAsProfessor();

    $url = $this->post('/api/v1/professor/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.jpg', 400, 400)], ['Accept' => 'application/json'])
        ->assertOk()
        ->json('data.avatar_url');

    expect($url)->toContain('avatar.jpg');
});

it('requires re-accepting the agreement when its version changes', function () {
    actingAsProfessor();
    config(['yekkola.terms.professor_agreement' => '2027-01']);

    $this->patchJson('/api/v1/professor/profile', ['headline' => 'x'])
        ->assertForbidden()
        ->assertJson(['code' => 'terms.acceptance_required', 'version' => '2027-01']);

    $this->getJson('/api/v1/professor/profile')->assertOk()->assertJsonPath('data.terms.accepted', false);

    $this->postJson('/api/v1/professor/terms/accept', ['version' => '2026-10-draft'])->assertUnprocessable();
    $this->postJson('/api/v1/professor/terms/accept', ['version' => '2027-01'])->assertOk()->assertJsonPath('data.terms.accepted', true);

    $this->patchJson('/api/v1/professor/profile', ['headline' => 'x'])->assertOk();
});

it('shows active professors publicly and hides suspended ones', function () {
    $profile = actingAsProfessor();

    $this->getJson('/api/v1/professors/marie-kabila')
        ->assertOk()
        ->assertJsonPath('data.display_name', 'Marie Kabila')
        ->assertJsonPath('data.is_verified', false)
        ->assertJsonMissingPath('data.payout_method')
        ->assertJsonMissingPath('data.status');

    $profile->update(['status' => 'suspended']);
    $this->getJson('/api/v1/professors/marie-kabila')->assertNotFound();
});
