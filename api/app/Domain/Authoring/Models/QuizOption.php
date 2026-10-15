<?php

declare(strict_types=1);

namespace App\Domain\Authoring\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $quiz_question_id
 * @property string $text
 * @property bool $is_correct
 * @property int $position
 */
final class QuizOption extends Model
{
    use HasUlids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_correct' => 'boolean', 'position' => 'integer'];
    }
}
