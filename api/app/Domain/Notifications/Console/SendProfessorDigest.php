<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Console;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Notifications\ProfessorDigest;
use App\Domain\Professors\Models\ProfessorProfile;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Daily professor digest (PRD-10 FR-06): the last 24 hours of sales (items that granted access), new reviews and
 * new questions on each professor's courses, counted from the records themselves — so it doesn't depend on the
 * in-app switches. Sent to active professors with an email, without the events they turned email off for. Run twice
 * on the same day, it sends nothing more.
 */
final class SendProfessorDigest extends Command
{
    protected $signature = 'notifications:send-digest';

    protected $description = 'Email professors a daily summary of their sales, reviews and questions';

    public function handle(Cache $cache): int
    {
        $since = now()->subDay();
        $courses = fn () => DB::table('courses')->select('id', 'professor_profile_id');
        $counts = [
            NotificationType::NewSale->value => DB::table('order_items as i')
                ->join('enrollments as e', 'e.order_item_id', '=', 'i.id')
                ->where('e.enrolled_at', '>=', $since)
                ->groupBy('i.professor_profile_id')->selectRaw('i.professor_profile_id as profile, count(*) as total')->pluck('total', 'profile'),
            NotificationType::NewReview->value => DB::table('reviews as r')->joinSub($courses(), 'c', 'c.id', '=', 'r.course_id')
                ->where('r.created_at', '>=', $since)
                ->groupBy('c.professor_profile_id')->selectRaw('c.professor_profile_id as profile, count(*) as total')->pluck('total', 'profile'),
            NotificationType::NewQuestion->value => DB::table('lesson_threads as t')->joinSub($courses(), 'c', 'c.id', '=', 't.course_id')
                ->where('t.created_at', '>=', $since)
                ->groupBy('c.professor_profile_id')->selectRaw('c.professor_profile_id as profile, count(*) as total')->pluck('total', 'profile'),
        ];

        $profileIds = collect($counts)->flatMap(fn (Collection $byProfile) => $byProfile->keys())->unique()->values();
        $sent = 0;
        foreach ($profileIds->chunk(200) as $chunk) {
            $profiles = ProfessorProfile::query()->with('user')->whereIn('id', $chunk)->get();
            $emailOff = NotificationPreference::query()->whereIn('user_id', $profiles->pluck('user_id'))
                ->where('channel', NotificationChannel::Email)->where('enabled', false)->get()
                ->groupBy('user_id')->map(fn ($prefs) => $prefs->map(fn (NotificationPreference $p) => $p->type->value)->all());

            foreach ($profiles as $profile) {
                $user = $profile->user;
                if ($user === null || $user->status !== UserStatus::Active || $user->isAnonymised() || $user->email === null || $user->email === '') {
                    continue;
                }
                $mine = [];
                foreach ($counts as $type => $byProfile) {
                    $total = (int) ($byProfile[$profile->id] ?? 0);
                    if ($total > 0 && ! in_array($type, $emailOff[$user->id] ?? [], true)) {
                        $mine[$type] = $total;
                    }
                }
                if ($mine === [] || ! $cache->add("digest:{$user->id}:".now()->toDateString(), true, now()->addDays(2))) {
                    continue;
                }

                $user->notify((new ProfessorDigest($mine))->locale($user->locale));
                $sent++;
            }
        }

        $this->info("Digests sent: {$sent}");

        return self::SUCCESS;
    }
}
