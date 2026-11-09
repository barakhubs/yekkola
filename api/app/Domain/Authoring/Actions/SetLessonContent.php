<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Actions;

use App\Domain\Authoring\Enums\LessonType;
use App\Domain\Authoring\Enums\QuestionType;
use App\Domain\Authoring\Exceptions\AuthoringException;
use App\Domain\Authoring\Models\Course;
use App\Domain\Authoring\Models\CourseVersion;
use App\Domain\Authoring\Models\Lesson;
use App\Domain\Protection\Stamping\DocumentNotStampable;
use App\Domain\Protection\Stamping\DocumentStamper;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Attaches content to a draft lesson (PRD-03 FR-04–07):
 * (video/audio uploads: MediaUploads)
 * - document: a PDF on the private disk;
 * - quiz: the full question set, replaced atomically.
 */
final class SetLessonContent
{
    public function __construct(
        private readonly EditDraft $editDraft,
        private readonly DocumentStamper $stamper,
    ) {}

    public function attachDocument(Course $course, string $lessonId, UploadedFile $file): Lesson
    {
        // Students only ever get stamped copies (PRD-07 FR-12): refuse a PDF the stamper can't read.
        try {
            $this->stamper->inspect((string) $file->getRealPath());
        } catch (DocumentNotStampable) {
            throw AuthoringException::documentNotSupported();
        }

        return $this->editDraft->withDraft($course, function (CourseVersion $draft) use ($lessonId, $file): Lesson {
            $lesson = $draft->lessons()->findOrFail($lessonId);

            if ($lesson->type !== LessonType::Document) {
                throw AuthoringException::lessonTypeMismatch();
            }

            $lesson->addMedia($file)->usingFileName('document.pdf')->toMediaCollection(Lesson::DOCUMENT);

            return $lesson;
        });
    }

    /**
     * @param  array{pass_mark_pct: int, time_limit_seconds?: int|null, max_attempts?: int|null, shuffle_questions?: bool, questions: list<array{type: string, prompt: string, explanation?: string|null, points?: int, options: list<array{text: string, is_correct: bool}>}>}  $data
     */
    public function saveQuiz(Course $course, string $lessonId, array $data): Lesson
    {
        $this->assertValidQuestions($data['questions']);

        return $this->editDraft->withDraft($course, function (CourseVersion $draft) use ($lessonId, $data): Lesson {
            $lesson = $draft->lessons()->findOrFail($lessonId);

            if ($lesson->type !== LessonType::Quiz) {
                throw AuthoringException::lessonTypeMismatch();
            }

            $quiz = $lesson->quiz()->firstOrCreate([]);
            $quiz->update([
                'pass_mark_pct' => $data['pass_mark_pct'],
                'time_limit_seconds' => $data['time_limit_seconds'] ?? null,
                'max_attempts' => $data['max_attempts'] ?? null,
                'shuffle_questions' => $data['shuffle_questions'] ?? false,
            ]);

            $quiz->questions()->delete();

            foreach ($data['questions'] as $qi => $question) {
                $created = $quiz->questions()->create([
                    'type' => QuestionType::from($question['type']),
                    'prompt' => $question['prompt'],
                    'explanation' => $question['explanation'] ?? null,
                    'points' => $question['points'] ?? 1,
                    'position' => $qi + 1,
                ]);

                foreach ($question['options'] as $oi => $option) {
                    $created->options()->create([
                        'text' => $option['text'],
                        'is_correct' => $option['is_correct'],
                        'position' => $oi + 1,
                    ]);
                }
            }

            return $lesson->load('quiz.questions.options');
        });
    }

    /**
     * @param  list<array{type: string, options: list<array{is_correct: bool}>}>  $questions
     */
    private function assertValidQuestions(array $questions): void
    {
        $errors = [];

        foreach ($questions as $i => $question) {
            $correct = count(array_filter($question['options'], fn (array $o) => $o['is_correct']));
            $total = count($question['options']);

            $valid = match (QuestionType::from($question['type'])) {
                QuestionType::Single => $correct === 1 && $total >= 2,
                QuestionType::Multiple => $correct >= 1 && $total >= 2,
                QuestionType::TrueFalse => $correct === 1 && $total === 2,
            };

            if (! $valid) {
                $errors["questions.{$i}.options"] = __('authoring.quiz.invalid_options');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
