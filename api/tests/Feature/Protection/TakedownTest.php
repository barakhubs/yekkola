<?php

declare(strict_types=1);

use App\Domain\Authoring\Models\Lesson;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Activity;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $professor = actingAsProfessor();
    $this->course = publishedCourse($professor, 'Algèbre pour l’Exetat', ['is_free' => true, 'price_minor' => null, 'currency' => null]);
    $lessons = Lesson::query()->where('course_version_id', $this->course->live_version_id)->orderBy('position')->get();
    [$this->document, , $this->video] = [$lessons[0], $lessons[1], $lessons[2]];

    $this->student = User::factory()->create(['name' => 'Grâce Mbuyi']);
    Sanctum::actingAs($this->student);
    $this->postJson("/api/v1/courses/{$this->course->id}/enroll")->assertOk();

    $this->admin = actingAsStaff(Role::Admin);
});

function asStudent(): void
{
    Sanctum::actingAs(test()->student);
}

function asAdmin(): void
{
    Sanctum::actingAs(test()->admin);
}

it('closes a taken-down course to everyone, enrolled students included, until restored', function () {
    asAdmin();
    $this->postJson("/api/v1/admin/courses/{$this->course->id}/take-down", ['note' => 'Plainte pour droits d’auteur'])->assertOk()
        ->assertJsonPath('data.status', 'unpublished')
        ->assertJsonPath('data.takedown_reason', 'Plainte pour droits d’auteur')
        ->assertJsonPath('data.can', ['unpublish' => false, 'republish' => false, 'take_down' => false, 'restore' => true]);

    asStudent();
    $this->postJson("/api/v1/lessons/{$this->video->uid}/playback")->assertForbidden()->assertJsonPath('code', 'enrollment.course_taken_down');
    $this->getJson("/api/v1/lessons/{$this->document->uid}/document")->assertForbidden()->assertJsonPath('code', 'enrollment.course_taken_down');
    $this->getJson("/api/v1/courses/{$this->course->slug}")->assertNotFound();

    asAdmin();
    $this->postJson("/api/v1/admin/courses/{$this->course->id}/restore", ['note' => 'Plainte retirée'])->assertOk()->assertJsonPath('data.status', 'published');

    asStudent();
    $this->postJson("/api/v1/lessons/{$this->video->uid}/playback")->assertOk();
    expect(Activity::query()->whereIn('event', ['course.taken_down', 'course.restored'])->count())->toBe(2);
});

it('unpublishes for new students only: enrolled students keep their access', function () {
    asAdmin();
    $this->postJson("/api/v1/admin/courses/{$this->course->id}/unpublish", ['note' => 'Mise à jour en cours'])->assertOk();
    $this->postJson("/api/v1/admin/courses/{$this->course->id}/unpublish", ['note' => 'Encore'])->assertConflict()
        ->assertJsonPath('code', 'course.availability_transition');

    asStudent();
    $this->getJson("/api/v1/courses/{$this->course->slug}")->assertNotFound();
    $this->postJson("/api/v1/lessons/{$this->video->uid}/playback")->assertOk();

    asAdmin();
    $this->postJson("/api/v1/admin/courses/{$this->course->id}/republish", ['note' => 'Prêt'])->assertOk()->assertJsonPath('data.status', 'published');
    $this->getJson('/api/v1/admin/courses?filter[q]=algèbre')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/admin/courses/{$this->course->id}/take-down", [])->assertUnprocessable(); // reason required
});

it('needs catalog.manage to act, catalog.view to list', function () {
    Sanctum::actingAs(actingAsStaff(Role::Moderator));

    $this->getJson('/api/v1/admin/courses')->assertOk();
    $this->postJson("/api/v1/admin/courses/{$this->course->id}/take-down", ['note' => 'Test'])->assertForbidden();
});
