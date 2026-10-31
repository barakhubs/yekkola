<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin dashboard (PRD-09 FR-01): one row per day, recomputed by `stats:rollup-platform`.
        Schema::create('platform_daily_stats', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->unsignedInteger('signups')->default(0);
            $table->unsignedInteger('enrollments')->default(0);
            $table->unsignedInteger('orders_created')->default(0);
            $table->unsignedInteger('orders_paid')->default(0);
            $table->unsignedInteger('courses_published')->default(0);
            // Money per currency, minor units: {"USD": 1500, "CDF": 250000}.
            $table->jsonb('gmv_minor')->default('{}');
            $table->jsonb('net_revenue_minor')->default('{}');
            $table->jsonb('refunds_minor')->default('{}');
            $table->jsonb('payouts_minor')->default('{}');
            $table->timestamps();
        });

        // "Active students" on the dashboard looks at recent learning activity.
        Schema::table('enrollments', function (Blueprint $table) {
            $table->index('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropIndex(['last_activity_at']);
        });
        Schema::dropIfExists('platform_daily_stats');
    }
};
