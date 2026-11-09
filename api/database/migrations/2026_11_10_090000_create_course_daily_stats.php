<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Professor analytics (arch §3, §7):
 * - `course_daily_stats`: one row per course and day, rolled up hourly, so dashboards never scan raw tables;
 * - `course_activity_days`: append-only "this student studied this course that day" (progress overwrites its
 *   timestamps, so past days can't be read back from it);
 * - an index on `order_items.granted_at` (when the sale gave access; kept when the enrollment is later re-granted);
 * - indexes on the dates the rollup reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('course_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('enrollments')->default(0);     // access granted that day, any source
            $table->unsignedInteger('sales')->default(0);           // paid order items that granted access
            $table->jsonb('revenue_minor')->default('{}');          // what students paid for those sales, per currency
            $table->unsignedInteger('completions')->default(0);
            $table->unsignedInteger('active_students')->default(0); // distinct students who studied that day
            $table->unsignedInteger('reviews')->default(0);         // new visible reviews
            $table->unsignedInteger('quiz_attempts')->default(0);   // submitted
            $table->unsignedInteger('quiz_passes')->default(0);
            $table->timestamps();

            $table->unique(['course_id', 'date']);
            $table->index('date');
        });

        // Large and append-only: no foreign keys (keeps it cheap to write and easy to partition by date later).
        Schema::create('course_activity_days', function (Blueprint $table) {
            $table->ulid('course_id');
            $table->date('date');
            $table->ulid('user_id');

            $table->primary(['course_id', 'date', 'user_id']);
            $table->index(['date', 'course_id']);
        });

        // Sales by the day they gave access (`granted_at`, set by FulfilOrder).
        Schema::table('order_items', function (Blueprint $table) {
            $table->index('granted_at');
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->index('enrolled_at');
        });
        DB::statement('CREATE INDEX enrollments_completed_at_index ON enrollments (completed_at) WHERE completed_at IS NOT NULL');
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropIndex(['submitted_at']);
        });
        DB::statement('DROP INDEX IF EXISTS enrollments_completed_at_index');
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropIndex(['enrolled_at']);
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['granted_at']);
        });
        Schema::dropIfExists('course_activity_days');
        Schema::dropIfExists('course_daily_stats');
    }
};
