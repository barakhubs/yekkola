<?php

declare(strict_types=1);

namespace App\Domain\Learning\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Learning\Enums\ThreadStatus;
use App\Domain\Learning\Exceptions\LearningException;
use App\Domain\Learning\Models\LessonThread;
use App\Domain\Learning\Models\ThreadReply;
use App\Domain\Learning\QaAccess;
use Illuminate\Database\ConnectionInterface;

/**
 * Replies to a question (PRD-06 FR-13). A reply from the course's professor is marked and answers the question.
 * The thread row is locked so concurrent replies keep `reply_count` right. Hidden questions take no replies.
 */
final class ReplyToThread
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly QaAccess $qa,
    ) {}

    public function handle(User $author, LessonThread $thread, string $body): ThreadReply
    {
        $course = $thread->course;
        if ($course === null) {
            throw LearningException::qaAccessRequired();
        }
        $this->qa->assertParticipant($author, $course);
        $fromProfessor = QaAccess::isProfessorOf($author, $course);

        return $this->db->transaction(function () use ($author, $thread, $body, $fromProfessor): ThreadReply {
            $thread = LessonThread::query()->whereKey($thread->id)->lockForUpdate()->firstOrFail();
            if ($thread->isHidden()) {
                throw LearningException::qaAccessRequired();
            }

            $reply = ThreadReply::query()->create([
                'lesson_thread_id' => $thread->id,
                'author_id' => $author->id,
                'body' => $body,
                'is_professor' => $fromProfessor,
            ]);

            $thread->forceFill([
                'reply_count' => $thread->reply_count + 1,
                'last_reply_at' => now(),
                ...($fromProfessor && $thread->status === ThreadStatus::Open
                    ? ['status' => ThreadStatus::Answered, 'answered_at' => now()] : []),
            ])->save();

            return $reply;
        });
    }
}
