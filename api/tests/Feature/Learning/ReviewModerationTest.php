<?php

declare(strict_types=1);

use App\Domain\Authoring\Models\Lesson;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\Review;
use App\Domain\Platform\Models\Activity;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->professor = actingAsProfessor('Marie Kabila');
    $this->course = publishedCourse($this->professor, 'Algèbre pour l’Exetat', ['is_free' => true, 'price_minor' => null, 'currency' => null]);
    $document = Lesson::query()->where('course_version_id', $this->course->live_version_id)->orderBy('position')->firstOrFail();

    foreach ([['Grâce', 5, 'Excellent.'], ['Jean', 1, 'Insultes et spam.']] as [$name, $rating, $body]) {
        Sanctum::actingAs(User::factory()->create(['name' => $name]));
        $this->postJson("/api/v1/courses/{$this->course->id}/enroll")->assertOk();
        $this->putJson('/api/v1/me/progress', ['items' => [['lesson_uid' => $document->uid, 'completed' => true]]])->assertOk();
        $this->postJson("/api/v1/courses/{$this->course->id}/review", ['rating' => $rating, 'body' => $body])->assertCreated();
    }
    $this->abusive = Review::query()->where('rating', 1)->sole();
    $this->moderator = actingAsStaff(Role::Moderator);
});

it('hides a review from the course page and the ratings at once, then restores it', function () {
    expect($this->course->refresh()->rating_count)->toBe(2);

    $this->getJson('/api/v1/admin/reviews?filter[q]=spam')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.can', ['hide' => true, 'restore' => false]);
    $this->postJson("/api/v1/admin/reviews/{$this->abusive->id}/hide", ['note' => 'Propos injurieux'])->assertOk()
        ->assertJsonPath('data.status', 'hidden')
        ->assertJsonPath('data.hidden_reason', 'Propos injurieux');
    $this->postJson("/api/v1/admin/reviews/{$this->abusive->id}/hide", ['note' => 'Encore'])->assertConflict();

    $course = $this->course->refresh();
    expect($course->rating_count)->toBe(1)
        ->and((float) $course->rating_avg)->toBe(5.0)
        ->and($this->professor->refresh()->rating_count)->toBe(1);
    $this->getJson("/api/v1/courses/{$this->course->slug}/reviews")->assertOk()->assertJsonCount(1, 'data');

    $this->postJson("/api/v1/admin/reviews/{$this->abusive->id}/restore", ['note' => 'Erreur'])->assertOk();
    expect($this->course->refresh()->rating_count)->toBe(2)
        ->and(Activity::query()->whereIn('event', ['review.hidden', 'review.restored'])->count())->toBe(2);
});

it('is for staff who handle reports, with a reason', function () {
    $this->postJson("/api/v1/admin/reviews/{$this->abusive->id}/hide", [])->assertUnprocessable();

    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/admin/reviews')->assertForbidden();
});
