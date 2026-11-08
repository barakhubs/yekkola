<?php

declare(strict_types=1);

namespace App\Http\Resources\Learning;

use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\LessonThread;
use App\Domain\Learning\Models\ThreadReply;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A lesson question as participants see it (PRD-06 FR-13): short author names, the professor's posts marked,
 * replies when loaded. `hidden` is only ever true for the author's own hidden posts.
 *
 * @mixin LessonThread
 */
final class ThreadResource extends JsonResource
{
    /** The course professor's user id, to mark their posts. */
    private ?string $professorUserId = null;

    /** Course and lesson titles, for the professor's inbox. */
    private ?string $lessonTitle = null;

    public function withProfessor(?string $userId): self
    {
        $this->professorUserId = $userId;

        return $this;
    }

    public function withLessonTitle(?string $title): self
    {
        $this->lessonTitle = $title;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'lesson_uid' => $this->lesson_uid,
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status->value,
            'author' => [
                'name' => User::shortNameOf($this->author?->name),
                'is_professor' => $this->author_id === $this->professorUserId,
                'is_me' => $this->author_id === $request->user()?->getAuthIdentifier(),
            ],
            'reply_count' => $this->reply_count,
            'last_reply_at' => $this->last_reply_at?->toIso8601String(),
            'answered_at' => $this->answered_at?->toIso8601String(),
            'hidden' => $this->isHidden(),
            'created_at' => $this->created_at->toIso8601String(),
            'lesson_title' => $this->when($this->lessonTitle !== null, fn () => $this->lessonTitle),
            'course' => $this->when($this->relationLoaded('course') && $this->lessonTitle !== null, fn () => [
                'id' => $this->course_id,
                'title' => $this->course?->liveVersion?->title,
            ]),
            /** @var list<array{id: string, body: string, author: array{name: string|null, is_professor: bool, is_me: bool}, hidden: bool, created_at: string}> */
            'replies' => $this->when($this->relationLoaded('replies'), fn () => $this->replies->map(fn (ThreadReply $reply) => [
                'id' => $reply->id,
                'body' => $reply->body,
                'author' => [
                    'name' => User::shortNameOf($reply->author?->name),
                    'is_professor' => $reply->is_professor,
                    'is_me' => $reply->author_id === $request->user()?->getAuthIdentifier(),
                ],
                'hidden' => $reply->isHidden(),
                'created_at' => $reply->created_at->toIso8601String(),
            ])->values()->all()),
        ];
    }
}
