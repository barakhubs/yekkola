<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Course authoring (PRD-03, architecture §3.3): categories, courses, immutable-once-approved versions,
 * sections, lessons (stable `uid` across versions), media assets on the video provider, quizzes,
 * and the inbound webhook inbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('parent_id')->nullable();
            $table->string('slug', 80)->unique();
            $table->jsonb('name');
            $table->jsonb('description')->nullable();
            $table->string('icon', 40)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();

            $table->index(['parent_id', 'position']);
        });

        // Self-reference added once the primary key exists.
        Schema::table('categories', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('categories')->nullOnDelete();
        });

        Schema::create('media_assets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('professor_profile_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8); // video | audio
            $table->string('provider', 16);
            $table->string('provider_upload_id')->nullable()->unique();
            $table->string('provider_asset_id')->nullable()->unique();
            $table->string('playback_id')->nullable();
            $table->string('status', 16)->index(); // waiting_upload | processing | ready | errored | cancelled
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('professor_profile_id');
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('professor_profile_id')->constrained()->restrictOnDelete();
            $table->string('slug', 100)->unique();
            $table->string('status', 16)->index(); // draft | in_review | published | unpublished | archived
            $table->ulid('live_version_id')->nullable();
            $table->ulid('draft_version_id')->nullable();
            $table->foreignUlid('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('language', 2)->default('fr');
            $table->string('level', 16)->default('all'); // beginner | intermediate | advanced | all
            $table->jsonb('audience')->default('[]');
            $table->boolean('is_free')->default(false);
            $table->bigInteger('price_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedSmallInteger('revenue_share_bps')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('enrollment_count')->default(0);
            $table->unsignedInteger('total_duration_seconds')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('professor_profile_id');
            $table->index('category_id');
            $table->index(['is_free', 'status']);
            $table->index('published_at');
        });

        Schema::create('course_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('state', 16)->index(); // draft | in_review | rejected | approved | superseded
            $table->string('title', 80)->nullable();
            $table->string('subtitle', 120)->nullable();
            $table->text('description')->nullable();
            $table->jsonb('outcomes')->default('[]');
            $table->jsonb('requirements')->default('[]');
            $table->text('changelog')->nullable();
            $table->timestamp('submitted_at')->nullable()->index();
            $table->foreignUlid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->unique(['course_id', 'number']);
            $table->index('reviewed_by');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->foreign('live_version_id')->references('id')->on('course_versions')->nullOnDelete();
            $table->foreign('draft_version_id')->references('id')->on('course_versions')->nullOnDelete();
        });

        Schema::create('sections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('course_version_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->index(['course_version_id', 'position']);
        });

        Schema::create('lessons', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('course_version_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('section_id')->constrained()->cascadeOnDelete();
            $table->ulid('uid'); // stable across versions: progress, quiz history, and Q&A follow it
            $table->string('type', 16); // video | audio | document | quiz
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->unsignedInteger('position');
            $table->boolean('is_preview')->default(false);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->foreignUlid('media_asset_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['course_version_id', 'uid']);
            $table->index(['section_id', 'position']);
            $table->index('uid');
            $table->index('media_asset_id');
        });

        Schema::create('quizzes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('lesson_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('pass_mark_pct')->default(50);
            $table->unsignedInteger('time_limit_seconds')->nullable();
            $table->unsignedSmallInteger('max_attempts')->nullable();
            $table->boolean('shuffle_questions')->default(false);
            $table->timestamps();
        });

        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('quiz_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16); // single | multiple | true_false
            $table->text('prompt');
            $table->text('explanation')->nullable();
            $table->unsignedSmallInteger('points')->default(1);
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->index(['quiz_id', 'position']);
        });

        Schema::create('quiz_options', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('quiz_question_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->index(['quiz_question_id', 'position']);
        });

        // Inbound provider webhooks (Mux; payments use their own table later). Deduplicated by event id.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('provider', 16);
            $table->string('event_id');
            $table->string('type', 64);
            $table->jsonb('payload');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
            $table->index('processed_at');
        });

        // At most one draft-like version (draft, in review, or rejected) per course.
        DB::statement("CREATE UNIQUE INDEX course_versions_one_open ON course_versions (course_id) WHERE state IN ('draft','in_review','rejected')");
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('quiz_options');
        Schema::dropIfExists('quiz_questions');
        Schema::dropIfExists('quizzes');
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('sections');
        Schema::table('courses', function (Blueprint $table) {
            $table->dropForeign(['live_version_id']);
            $table->dropForeign(['draft_version_id']);
        });
        Schema::dropIfExists('course_versions');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('media_assets');
        Schema::dropIfExists('categories');
    }
};
