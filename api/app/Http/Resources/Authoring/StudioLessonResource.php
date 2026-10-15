<?php

declare(strict_types=1);

namespace App\Http\Resources\Authoring;

use App\Domain\Authoring\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A lesson in the studio/review screen, including the quiz answer key. Never for students.
 *
 * @mixin Lesson
 */
final class StudioLessonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $document = $this->getFirstMedia(Lesson::DOCUMENT);
        $quiz = $this->quiz;

        return [
            'id' => $this->id,
            'uid' => $this->uid,
            'section_id' => $this->section_id,
            'type' => $this->type->value,
            'title' => $this->title,
            'description' => $this->description,
            'position' => $this->position,
            'is_preview' => $this->is_preview,
            'duration_seconds' => $this->duration_seconds,
            'is_content_ready' => $this->isContentReady(),
            'media' => $this->mediaAsset ? new MediaAssetResource($this->mediaAsset) : null,
            'document' => $document ? ['name' => $document->file_name, 'size' => $document->size] : null,
            'quiz' => $quiz ? [
                'pass_mark_pct' => $quiz->pass_mark_pct,
                'time_limit_seconds' => $quiz->time_limit_seconds,
                'max_attempts' => $quiz->max_attempts,
                'shuffle_questions' => $quiz->shuffle_questions,
                'questions' => $quiz->questions->map(fn ($q) => [
                    'id' => $q->id,
                    'type' => $q->type->value,
                    'prompt' => $q->prompt,
                    'explanation' => $q->explanation,
                    'points' => $q->points,
                    'options' => $q->options->map(fn ($o) => [
                        'id' => $o->id,
                        'text' => $o->text,
                        'is_correct' => $o->is_correct,
                    ])->values()->all(),
                ])->values()->all(),
            ] : null,
        ];
    }
}
