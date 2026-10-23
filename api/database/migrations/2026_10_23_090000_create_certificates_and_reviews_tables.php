<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Certificates (PRD-06 FR-10, FR-11): names and title snapshotted at issue, one valid certificate per
 * student and course (a revoked one stays verifiable as revoked). Reviews (FR-12): one per student and course.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('serial', 32)->unique(); // public, random — the verification link
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('course_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('course_version_id')->constrained()->restrictOnDelete();
            $table->string('student_name_snapshot');
            $table->string('course_title_snapshot');
            $table->string('professor_name_snapshot');
            $table->timestamp('issued_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 64)->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('course_id');
            $table->index('enrollment_id');
            $table->index('course_version_id');
        });
        DB::statement('create unique index certificates_one_valid on certificates (user_id, course_id) where revoked_at is null');

        Schema::create('reviews', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('course_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('rating'); // 1–5
            $table->text('body')->nullable();
            $table->string('status', 16)->default('visible'); // visible | hidden
            $table->text('professor_reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'course_id']);
            $table->index(['course_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('certificates');
    }
};
