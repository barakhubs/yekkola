<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PRD-06 FR-14, PRD-09 FR-10: users flag content; staff resolve all open reports on one item together.
        Schema::create('reports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reportable_type', 20);
            $table->ulid('reportable_id');
            $table->string('reason', 20);
            $table->string('details', 1000)->nullable();
            $table->string('status', 20)->default('open');
            $table->string('action', 20)->nullable();
            $table->foreignUlid('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution_note', 2000)->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['reportable_type', 'reportable_id', 'status']);
        });

        // One open report per person and item: reporting again while it's open changes nothing.
        DB::statement("CREATE UNIQUE INDEX reports_one_open_per_reporter ON reports (reporter_id, reportable_type, reportable_id) WHERE status = 'open'");
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
