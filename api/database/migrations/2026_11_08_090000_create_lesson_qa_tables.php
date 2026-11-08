<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PRD-06 FR-13: questions per lesson, keyed by the lesson uid so they survive new versions.
        Schema::create('lesson_threads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('course_id')->constrained()->cascadeOnDelete();
            $table->ulid('lesson_uid');
            $table->foreignUlid('author_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('body');
            $table->string('status', 20)->default('open');
            $table->unsignedInteger('reply_count')->default(0);
            $table->timestamp('last_reply_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->foreignUlid('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('hidden_reason', 2000)->nullable();
            $table->timestamps();

            $table->index(['course_id', 'lesson_uid', 'updated_at']);
            $table->index(['course_id', 'status', 'created_at']);
            $table->index(['hidden_at', 'created_at']);
        });

        Schema::create('thread_replies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('lesson_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('author_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_professor')->default(false);
            $table->timestamp('hidden_at')->nullable();
            $table->foreignUlid('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('hidden_reason', 2000)->nullable();
            $table->timestamps();

            $table->index(['lesson_thread_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_replies');
        Schema::dropIfExists('lesson_threads');
    }
};
