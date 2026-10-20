<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learning (PRD-06): progress per lesson uid (survives course versions; the largest table — no cascades,
 * see architecture §7) and quiz attempts graded against the quiz version they started on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_progress', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('user_id');
            $table->ulid('course_id');
            $table->string('lesson_uid', 26);
            $table->string('status', 16); // in_progress | completed
            $table->unsignedInteger('position_seconds')->default(0);
            $table->timestamp('completed_at')->nullable(); // server time
            $table->timestamp('client_updated_at')->nullable(); // device time, ordering only
            $table->ulid('device_id')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'lesson_uid']);
            $table->index(['user_id', 'course_id']);
        });

        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('quiz_id')->constrained()->restrictOnDelete(); // the version it started on
            $table->ulid('course_id');
            $table->string('lesson_uid', 26);
            $table->jsonb('question_order'); // question ids as shown (shuffled or not)
            $table->jsonb('answers')->nullable(); // {question_id: [option ids]}
            $table->timestamp('started_at');
            $table->timestamp('deadline_at')->nullable(); // time limit + grace
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedTinyInteger('score_pct')->nullable();
            $table->boolean('passed')->nullable();
            $table->boolean('timed_out')->default(false);
            $table->ulid('device_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'lesson_uid']);
            $table->index('quiz_id');
            $table->index('course_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempts');
        Schema::dropIfExists('lesson_progress');
    }
};
