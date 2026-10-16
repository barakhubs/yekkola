<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds (PRD-05 FR-16, PRD-09 FR-12): a course refunded at a student's request, or money collected but
 * never applied (amount mismatch, duplicate payment, already owned) sent back. Paid out as a disbursement
 * to the original payer; each attempt has its own gateway reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('kind', 16); // item | unapplied
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('payment_id')->constrained()->restrictOnDelete(); // the collection refunded
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 16)->index(); // requested | approved | rejected | processing | completed | failed
            $table->text('reason')->nullable();
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->unsignedSmallInteger('attempt')->default(0); // gateway reference = R-{id}-{attempt}
            $table->string('gateway', 32)->nullable();
            $table->string('gateway_reference', 128)->nullable();
            $table->string('operator_reference', 128)->nullable();
            $table->bigInteger('fee_minor')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message', 255)->nullable();
            $table->unsignedInteger('checks')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index('order_item_id');
            $table->index('payment_id');
            $table->index('requested_by');
            $table->index('decided_by');
            $table->index(['status', 'last_checked_at']);
        });

        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_amount CHECK (amount_minor > 0 AND (kind = 'unapplied' OR order_item_id IS NOT NULL))");
        // One live refund per course bought, and per unapplied payment (a rejected one can be asked again).
        DB::statement("CREATE UNIQUE INDEX refunds_one_per_item ON refunds (order_item_id) WHERE kind = 'item' AND status <> 'rejected'");
        DB::statement("CREATE UNIQUE INDEX refunds_one_per_unapplied ON refunds (payment_id) WHERE kind = 'unapplied' AND status <> 'rejected'");
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
