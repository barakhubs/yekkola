<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Learning\Models\LessonThread;
use App\Domain\Learning\Models\ThreadReply;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A lesson question with every reply, as moderators see it (PRD-09 FR-10): full author names and ids, hidden
 * posts included with who hid them and why, and what may be done (`can`).
 *
 * @mixin LessonThread
 */
final class AdminThreadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status->value,
            'author' => ['id' => $this->author_id, 'name' => $this->author?->name],
            'course' => ['id' => $this->course_id, 'slug' => $this->course?->slug, 'title' => $this->course?->liveVersion?->title],
            'lesson_uid' => $this->lesson_uid,
            'hidden_at' => $this->hidden_at?->toIso8601String(),
            'hidden_reason' => $this->hidden_reason,
            'created_at' => $this->created_at->toIso8601String(),
            'can' => ['hide' => ! $this->isHidden(), 'restore' => $this->isHidden()],
            /** @var list<array{id: string, body: string, is_professor: bool, author: array{id: string, name: string|null}, hidden_at: string|null, hidden_reason: string|null, created_at: string, can: array{hide: bool, restore: bool}}> */
            'replies' => $this->replies->map(fn (ThreadReply $reply) => [
                'id' => $reply->id,
                'body' => $reply->body,
                'is_professor' => $reply->is_professor,
                'author' => ['id' => $reply->author_id, 'name' => $reply->author?->name],
                'hidden_at' => $reply->hidden_at?->toIso8601String(),
                'hidden_reason' => $reply->hidden_reason,
                'created_at' => $reply->created_at->toIso8601String(),
                'can' => ['hide' => ! $reply->isHidden(), 'restore' => $reply->isHidden()],
            ])->values()->all(),
        ];
    }
}
