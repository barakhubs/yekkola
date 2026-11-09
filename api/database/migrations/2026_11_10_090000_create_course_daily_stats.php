<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per course and day for the professor analytics dashboard (arch §3, §7): rolled up from the source
 * tables, so dashboards never scan enrollments, orders or progress. Money per currency (jsonb), minor units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('course_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('enrollments')->default(0);   // new access, any source
            $table->unsignedInteger('sales')->default(0);         // paid order items that granted access
            $table->jsonb('revenue_minor')->default('{}');        // what students paid for those sales, per currency
            $table->unsignedInteger('completions')->default(0);
            $table->unsignedInteger('active_students')->default(0); // distinct students with progress that day
            $table->unsignedInteger('reviews')->default(0);       // new published reviews
            $table->unsignedInteger('quiz_attempts')->default(0); // submitted
            $table->unsignedInteger('quiz_passes')->default(0);
            $table->timestamps();

            $table->unique(['course_id', 'date']);
            $table->index('date');
        });

        // Daily "active students" reads progress by course and day.
        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->index(['course_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->dropIndex(['course_id', 'updated_at']);
        });
        Schema::dropIfExists('course_daily_stats');
    }
};
