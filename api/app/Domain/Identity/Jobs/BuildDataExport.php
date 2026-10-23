<?php

declare(strict_types=1);

namespace App\Domain\Identity\Jobs;

use App\Domain\Authoring\Models\Course;
use App\Domain\Commerce\Models\Order;
use App\Domain\Identity\Enums\DataExportStatus;
use App\Domain\Identity\Models\DataExport;
use App\Domain\Learning\Models\Certificate;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Learning\Models\LessonProgress;
use App\Domain\Learning\Models\QuizAttempt;
use App\Domain\Learning\Models\Review;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Builds the JSON export of a user's personal data (PRD-01 FR-12) on the private disk.
 * Later phases add their data here (enrollments, orders, certificates, reviews…).
 */
final class BuildDataExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public readonly string $exportId) {}

    public function handle(): void
    {
        $export = DataExport::query()->with('user.province', 'user.devices')->findOrFail($this->exportId);
        $user = $export->user;

        $data = [
            'generated_at' => now()->toIso8601String(),
            'profile' => [
                'id' => $user->id,
                'phone' => $user->phone_e164,
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'province' => $user->province?->name,
                'city' => $user->city,
                'created_at' => $user->created_at->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],
            'roles' => $user->getRoleNames()->all(),
            'devices' => $user->devices->map(fn ($d) => [
                'platform' => $d->platform->value,
                'name' => $d->name,
                'registered_at' => $d->registered_at->toIso8601String(),
                'last_active_at' => $d->last_active_at?->toIso8601String(),
                'revoked_at' => $d->revoked_at?->toIso8601String(),
            ])->all(),
            'orders' => Order::query()->where('buyer_id', $user->id)->orWhere('beneficiary_id', $user->id)->with('items')->orderBy('created_at')->get()
                ->map(fn (Order $o) => [
                    'number' => $o->number,
                    'status' => $o->status->value,
                    'total_minor' => $o->total_minor,
                    'currency' => $o->currency,
                    'courses' => $o->items->pluck('title_snapshot')->all(),
                    'created_at' => $o->created_at->toIso8601String(),
                    'paid_at' => $o->paid_at?->toIso8601String(),
                ])->all(),
            'enrollments' => Enrollment::query()->where('user_id', $user->id)->with('course')->get()
                ->map(fn (Enrollment $e) => [
                    'course' => $e->course?->slug,
                    'source' => $e->source->value,
                    'status' => $e->status->value,
                    'progress_pct' => $e->progress_pct,
                    'completed_at' => $e->completed_at?->toIso8601String(),
                    'enrolled_at' => $e->enrolled_at->toIso8601String(),
                ])->all(),
            'lesson_progress' => LessonProgress::query()->where('user_id', $user->id)->get()
                ->map(fn (LessonProgress $p) => ['lesson_uid' => $p->lesson_uid, 'status' => $p->status->value, 'position_seconds' => $p->position_seconds, 'completed_at' => $p->completed_at?->toIso8601String()])->all(),
            'quiz_attempts' => QuizAttempt::query()->where('user_id', $user->id)->get()
                ->map(fn (QuizAttempt $a) => ['lesson_uid' => $a->lesson_uid, 'started_at' => $a->started_at->toIso8601String(), 'submitted_at' => $a->submitted_at?->toIso8601String(), 'score_pct' => $a->score_pct, 'passed' => $a->passed])->all(),
            'wishlist' => Course::query()->join('wishlist_items as w', 'w.course_id', '=', 'courses.id')
                ->where('w.user_id', $user->id)->orderBy('w.created_at')->pluck('courses.slug')->all(),
            'certificates' => Certificate::query()->where('user_id', $user->id)->orderBy('issued_at')->get()
                ->map(fn (Certificate $c) => ['serial' => $c->serial, 'course' => $c->course_title_snapshot, 'name' => $c->student_name_snapshot, 'issued_at' => $c->issued_at->toIso8601String(), 'revoked_at' => $c->revoked_at?->toIso8601String()])->all(),
            'reviews' => Review::query()->where('user_id', $user->id)->with('course')->orderBy('created_at')->get()
                ->map(fn (Review $r) => ['course' => $r->course?->slug, 'rating' => $r->rating, 'body' => $r->body, 'created_at' => $r->created_at->toIso8601String(), 'updated_at' => $r->updated_at->toIso8601String()])->all(),
        ];

        $path = "exports/{$user->id}/{$export->id}.json";
        DataExport::disk()->put($path, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $export->forceFill([
            'status' => DataExportStatus::Ready,
            'path' => $path,
            'ready_at' => now(),
            'expires_at' => now()->addDays((int) config('yekkola.auth.export_ttl_days')),
        ])->save();
    }

    public function failed(Throwable $e): void
    {
        DataExport::query()->whereKey($this->exportId)->update(['status' => DataExportStatus::Failed]);
    }
}
