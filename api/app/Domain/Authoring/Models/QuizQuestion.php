<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Models;

use App\Domain\Authoring\Enums\QuestionType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $quiz_id
 * @property QuestionType $type
 * @property string $prompt
 * @property string|null $explanation
 * @property int $points
 * @property int $position
 */
final class QuizQuestion extends Model
{
    use HasUlids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['type' => QuestionType::class, 'points' => 'integer', 'position' => 'integer'];
    }

    /**
     * @return HasMany<QuizOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(QuizOption::class)->orderBy('position');
    }

    /**
     * Right only with exactly the correct options (no partial credit).
     *
     * @param  list<string>  $chosen
     */
    public function isAnsweredBy(array $chosen): bool
    {
        $correct = $this->options->filter(fn (QuizOption $o) => $o->is_correct)->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();
        $chosen = array_values(array_unique(array_map('strval', $chosen)));
        sort($chosen);

        return $chosen !== [] && $chosen === $correct;
    }
}
