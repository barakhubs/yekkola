<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\ThreadStatus;
use App\Domain\Learning\Exceptions\LearningException;
use App\Domain\Learning\Models\LessonThread;
use App\Domain\Learning\Models\ThreadReply;
use App\Domain\Platform\Actions\RecordAudit;
use Illuminate\Database\ConnectionInterface;

/**
 * Staff hide or restore a question or a reply (PRD-09 FR-10), with a reason in the audit log. Hidden content stays
 * visible to its author only. Hiding or restoring a professor reply re-derives whether the question is answered.
 * Moderation isn't activity: it leaves `updated_at` (the participants' ordering) alone.
 */
final class ModerateQa
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly RecordAudit $recordAudit,
    ) {}

    public function hide(LessonThread|ThreadReply $item, User $staff, string $reason): LessonThread|ThreadReply
    {
        return $this->change($item, $staff, $reason, true);
    }

    public function restore(LessonThread|ThreadReply $item, User $staff, string $reason): LessonThread|ThreadReply
    {
        return $this->change($item, $staff, $reason, false);
    }

    /** Answered while a visible professor reply remains; the first such reply dates it. */
    private function rederiveAnswered(string $threadId): void
    {
        $thread = LessonThread::query()->whereKey($threadId)->lockForUpdate()->firstOrFail();
        $first = ThreadReply::query()->where('lesson_thread_id', $thread->id)->where('is_professor', true)
            ->whereNull('hidden_at')->orderBy('created_at')->value('created_at');

        $thread->timestamps = false;
        $thread->forceFill($first === null
            ? ['status' => ThreadStatus::Open, 'answered_at' => null]
            : ['status' => ThreadStatus::Answered, 'answered_at' => $first])->save();
    }

    private function change(LessonThread|ThreadReply $item, User $staff, string $reason, bool $hide): LessonThread|ThreadReply
    {
        return $this->db->transaction(function () use ($item, $staff, $reason, $hide): LessonThread|ThreadReply {
            $locked = $item::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($locked->isHidden() === $hide) {
                throw LearningException::qaModerationTransition();
            }

            $locked->timestamps = false;
            $locked->forceFill($hide
                ? ['hidden_at' => now(), 'hidden_by' => $staff->id, 'hidden_reason' => $reason]
                : ['hidden_at' => null, 'hidden_by' => null, 'hidden_reason' => null])->save();

            if ($locked instanceof ThreadReply && $locked->is_professor) {
                $this->rederiveAnswered($locked->lesson_thread_id);
            }

            $kind = $locked instanceof LessonThread ? 'thread' : 'thread_reply';
            $this->recordAudit->handle("{$kind}.".($hide ? 'hidden' : 'restored'), $locked, ['reason' => $reason], causer: $staff);

            return $locked;
        });
    }
}
