<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\Enrollment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->professor = actingAsProfessor();
    $this->course = publishedCourse($this->professor, 'Algèbre', ['is_free' => false, 'price_minor' => 1_500, 'currency' => 'USD']);
});

function enrol(string $courseId, array $attributes = []): Enrollment
{
    return Enrollment::query()->forceCreate([
        'user_id' => User::factory()->create()->id, 'course_id' => $courseId, 'source' => 'admin',
        'status' => 'active', 'enrolled_at' => now(), ...$attributes,
    ]);
}

it('rolls up a day per course: sales that granted access, what students paid, completions — the same on every run', function () {
    $sold = enrol($this->course->id);
    paidOrderItemFor($sold);
    enrol($this->course->id, ['completed_at' => now(), 'progress_pct' => 100]);

    $this->artisan('stats:rollup-courses', ['--days' => 1])->assertSuccessful();
    $this->artisan('stats:rollup-courses', ['--days' => 1])->assertSuccessful();

    $row = DB::table('course_daily_stats')->where('course_id', $this->course->id)->sole();
    expect($row->enrollments)->toBe(2)
        ->and($row->sales)->toBe(1)
        ->and(json_decode($row->revenue_minor, true))->toBe(['USD' => 1_500])
        ->and($row->completions)->toBe(1);

    // A day recomputed to nothing loses its row.
    Enrollment::query()->update(['enrolled_at' => now()->subDays(5), 'completed_at' => null]);
    DB::table('order_items')->update(['granted_at' => now()->subDays(5)]);
    $this->artisan('stats:rollup-courses', ['--days' => 1])->assertSuccessful();
    expect(DB::table('course_daily_stats')->where('date', now()->toDateString())->count())->toBe(0);
});

it('keeps a past sale and past activity when the student comes back or is re-granted later', function () {
    $this->travelTo(now()->subDay()->setTime(22, 0));
    $student = User::factory()->create();
    $enrollment = enrol($this->course->id, ['user_id' => $student->id]);
    paidOrderItemFor($enrollment);
    $lesson = App\Domain\Authoring\Models\Lesson::query()->where('course_version_id', $this->course->live_version_id)->where('type', 'video')->sole();
    Sanctum::actingAs($student);
    $this->putJson('/api/v1/me/progress', ['items' => [['lesson_uid' => $lesson->uid, 'position_seconds' => 60]]])->assertOk();

    // Next morning: studied again, then refunded and given access another way.
    $this->travelTo(now()->addHours(10));
    $this->putJson('/api/v1/me/progress', ['items' => [['lesson_uid' => $lesson->uid, 'position_seconds' => 120]]])->assertOk();
    $enrollment->update(['status' => 'revoked']);
    app(App\Domain\Learning\Actions\GrantEnrollment::class)->handle($student->id, $this->course->id, App\Domain\Learning\Enums\EnrollmentSource::Admin);

    $this->artisan('stats:rollup-courses', ['--days' => 2])->assertSuccessful();
    $yesterday = DB::table('course_daily_stats')->where('course_id', $this->course->id)->where('date', now()->subDay()->toDateString())->sole();
    expect($yesterday->sales)->toBe(1)
        ->and($yesterday->active_students)->toBe(1)
        ->and(DB::table('course_daily_stats')->where('date', now()->toDateString())->value('active_students'))->toBe(1);

    // Two active days, one student.
    Sanctum::actingAs($this->professor->user);
    $this->getJson('/api/v1/professor/analytics/overview?days=7')->assertOk()
        ->assertJsonPath('data.totals.active_students', 1)
        ->assertJsonPath('data.changes.sales', null);
});

it('shows a professor their own courses only, over a chosen period with the one before', function () {
    paidOrderItemFor(enrol($this->course->id));
    $this->artisan('stats:rollup-courses', ['--days' => 1])->assertSuccessful();

    $this->getJson('/api/v1/professor/analytics/overview?days=7')->assertOk()
        ->assertJsonPath('data.period_days', 7)
        ->assertJsonCount(7, 'data.daily')
        ->assertJsonPath('data.totals.sales', 1)
        ->assertJsonPath('data.totals.revenue.0', ['amount_minor' => 1_500, 'currency' => 'USD'])
        ->assertJsonPath('data.previous.sales', 0)
        ->assertJsonPath('data.courses.0.course_id', $this->course->id);
    $this->getJson("/api/v1/professor/analytics/courses/{$this->course->id}")->assertOk()
        ->assertJsonPath('data.funnel.enrolled', 1);
    $this->getJson('/api/v1/professor/analytics/overview?days=12')->assertUnprocessable();

    $other = actingAsProfessor('Autre Professeur');
    $this->getJson('/api/v1/professor/analytics/overview')->assertOk()
        ->assertJsonPath('data.totals.sales', 0)
        ->assertJsonCount(0, 'data.courses');
    $this->getJson("/api/v1/professor/analytics/courses/{$this->course->id}")->assertNotFound();
    expect($other->id)->not->toBe($this->professor->id);

    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/professor/analytics/overview')->assertForbidden();
});
