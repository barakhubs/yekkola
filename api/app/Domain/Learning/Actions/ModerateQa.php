<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Learning\Exceptions\LearningException;
use App\Domain\Learning\Models\LessonThread;
use App\Domain\Learning\Models\ThreadReply;
use App\Domain\Platform\Actions\RecordAudit;
use Illuminate\Database\ConnectionInterface;

/**
 * Staff hide or restore a question or a reply (PRD-09 FR-10), with a reason in the audit log. Hidden content stays
 * visible to its author only. `reply_count` counts every reply, hidden ones too (it orders the inbox, not shown).
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

    private function change(LessonThread|ThreadReply $item, User $staff, string $reason, bool $hide): LessonThread|ThreadReply
    {
        return $this->db->transaction(function () use ($item, $staff, $reason, $hide): LessonThread|ThreadReply {
            $locked = $item::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($locked->isHidden() === $hide) {
                throw LearningException::qaModerationTransition();
            }

            $locked->forceFill($hide
                ? ['hidden_at' => now(), 'hidden_by' => $staff->id, 'hidden_reason' => $reason]
                : ['hidden_at' => null, 'hidden_by' => null, 'hidden_reason' => null])->save();

            $kind = $locked instanceof LessonThread ? 'thread' : 'thread_reply';
            $this->recordAudit->handle("{$kind}.".($hide ? 'hidden' : 'restored'), $locked, ['reason' => $reason], causer: $staff);

            return $locked;
        });
    }
}
