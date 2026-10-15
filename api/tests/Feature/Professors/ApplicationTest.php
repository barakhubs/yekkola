<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Professors\Enums\ApplicationStatus;
use App\Domain\Professors\Enums\LegalDocument;
use App\Domain\Professors\Models\ProfessorApplication;
use App\Domain\Professors\Models\TermsAcceptance;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    $this->applicant = User::factory()->create(['name' => 'Jean Mukendi']);
    Sanctum::actingAs($this->applicant);
});

function completeApplication(): array
{
    return [
        'full_name' => 'Jean Mukendi',
        'subjects' => ['Mathématiques', 'Physique'],
        'levels' => ['secondary', 'exetat'],
        'years_experience' => 8,
        'institutions' => 'Institut Technique, Bukavu',
        'motivation' => 'Aider les élèves à réussir l’Examen d’État.',
        'sample_url' => 'https://example.cd/lecon.mp4',
    ];
}

it('creates a draft prefilled from the account and autosaves it', function () {
    $this->putJson('/api/v1/professor/application', ['subjects' => ['Chimie']])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.full_name', 'Jean Mukendi')
        ->assertJsonPath('data.is_editable', true);

    $this->putJson('/api/v1/professor/application', ['years_experience' => 3])->assertOk();

    expect(ProfessorApplication::query()->count())->toBe(1)
        ->and(ProfessorApplication::query()->sole()->subjects)->toBe(['Chimie']);
});

it('validates the form fields', function (array $payload, string $field) {
    $this->putJson('/api/v1/professor/application', $payload)
        ->assertUnprocessable()
        ->assertJsonStructure(['errors' => [$field]]);
})->with([
    'unknown level' => [['levels' => ['kindergarten']], 'levels.0'],
    'sample not https' => [['sample_url' => 'http://example.cd/x'], 'sample_url'],
    'too much experience' => [['years_experience' => 99], 'years_experience'],
]);

it('lists missing fields when submitting an incomplete application', function () {
    $this->putJson('/api/v1/professor/application', ['subjects' => ['Chimie']]);

    $this->postJson('/api/v1/professor/application/submit', ['accept_terms' => true])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'application.incomplete')
        ->assertJson(['missing' => ['levels', 'years_experience', 'motivation']]);
});

it('requires accepting the professor agreement, and records the accepted version', function () {
    $this->putJson('/api/v1/professor/application', completeApplication());

    $this->postJson('/api/v1/professor/application/submit', ['accept_terms' => false])
        ->assertJsonPath('code', 'terms.not_accepted');

    $this->postJson('/api/v1/professor/application/submit', ['accept_terms' => true])
        ->assertOk()
        ->assertJsonPath('data.status', 'submitted')
        ->assertJsonPath('data.is_editable', false);

    expect(TermsAcceptance::hasAcceptedCurrent($this->applicant->id, LegalDocument::ProfessorAgreement))->toBeTrue()
        ->and(ProfessorApplication::query()->sole()->terms_version)->toBe(LegalDocument::ProfessorAgreement->currentVersion());
});

it('locks the application once submitted', function () {
    $this->putJson('/api/v1/professor/application', completeApplication());
    $this->postJson('/api/v1/professor/application/submit', ['accept_terms' => true]);

    $this->putJson('/api/v1/professor/application', ['motivation' => 'Changed'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'application.locked');
});

it('accepts up to 5 credential files on the private disk and lets the applicant remove them', function () {
    $this->putJson('/api/v1/professor/application', completeApplication());

    $id = $this->post('/api/v1/professor/application/credentials', ['file' => fakePdf()], ['Accept' => 'application/json'])
        ->assertCreated()
        ->json('data.credential_files.0.id');

    foreach (range(1, 4) as $_) {
        $this->post('/api/v1/professor/application/credentials', ['file' => UploadedFile::fake()->image('cert.jpg')], ['Accept' => 'application/json'])->assertCreated();
    }

    $this->post('/api/v1/professor/application/credentials', ['file' => fakePdf()], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'application.too_many_credentials');

    $this->deleteJson("/api/v1/professor/application/credentials/{$id}")->assertNoContent();

    expect(ProfessorApplication::query()->sole()->getMedia('credentials'))->toHaveCount(4)
        ->and(ProfessorApplication::query()->sole()->getMedia('credentials')->first()->disk)->toBe('local');
});

it('rejects credential files of the wrong type', function () {
    $this->putJson('/api/v1/professor/application', completeApplication());

    $this->post('/api/v1/professor/application/credentials', ['file' => UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload')], ['Accept' => 'application/json'])
        ->assertUnprocessable();
});

it('lets the applicant answer an info request and resubmit', function () {
    $this->putJson('/api/v1/professor/application', completeApplication());
    $this->postJson('/api/v1/professor/application/submit', ['accept_terms' => true]);
    ProfessorApplication::query()->update(['status' => ApplicationStatus::NeedsInfo, 'info_request_note' => 'Ajoutez votre diplôme.', 'info_requested_at' => now()]);

    $this->getJson('/api/v1/professor/application')->assertJsonPath('data.info_request_note', 'Ajoutez votre diplôme.');
    $this->putJson('/api/v1/professor/application', ['credentials' => 'Licence en mathématiques'])->assertOk();
    $this->postJson('/api/v1/professor/application/submit', ['accept_terms' => true])->assertJsonPath('data.status', 'submitted');
});

it('enforces the reapply cooldown after a rejection', function () {
    ProfessorApplication::query()->create([
        'user_id' => $this->applicant->id,
        'status' => ApplicationStatus::Rejected,
        'decided_at' => now(),
    ]);

    $this->putJson('/api/v1/professor/application', completeApplication())
        ->assertStatus(409)
        ->assertJsonPath('code', 'application.reapply_cooldown')
        ->assertHeader('Retry-After');

    $this->travel(31)->days();
    $this->putJson('/api/v1/professor/application', completeApplication())->assertCreated()->assertJsonPath('data.status', 'draft');
});

it('does not let an existing professor apply again', function () {
    actingAsProfessor();

    $this->putJson('/api/v1/professor/application', completeApplication())
        ->assertStatus(409)
        ->assertJsonPath('code', 'professor.already_professor');
});

it('expires info requests left unanswered for 30 days', function () {
    $application = ProfessorApplication::query()->create([
        'user_id' => $this->applicant->id,
        'status' => ApplicationStatus::NeedsInfo,
        'info_requested_at' => now(),
    ]);

    $this->travel(31)->days();
    $this->artisan('professors:expire-stale-applications')->assertSuccessful();

    expect($application->refresh()->status)->toBe(ApplicationStatus::Expired);
});
