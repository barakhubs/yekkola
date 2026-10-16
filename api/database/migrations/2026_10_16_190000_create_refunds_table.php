<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds (PRD-05 FR-16, PRD-09 FR-12): a course refunded at a student's request, or money collected but
 * never applied (amount mismatch, duplicate payment, already owned) sent back to the original payer. Each
 * payout attempt is its own row with its own gateway reference and ledger postings, so a late or second
 * success is always accounted for. Order items record when they granted access (what a reversal undoes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->timestamp('granted_at')->nullable(); // this order gave access to the course (and posted its sale)
        });
        // Orders paid before this column existed: the enrollment still points at the item that granted it.
        DB::statement('UPDATE order_items SET granted_at = o.paid_at FROM orders o, enrollments e WHERE o.id = order_items.order_id AND e.order_item_id = order_items.id AND o.paid_at IS NOT NULL');

        Schema::create('refunds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('kind', 16); // item | unapplied
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('payment_id')->constrained()->restrictOnDelete(); // the collection refunded
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 16)->index(); // requested | approved | rejected | processing | completed | failed | cancelled
            $table->string('flag', 32)->nullable()->index(); // needs staff attention: amount_mismatch, paid_twice, paid_after_reversal, payout_reversed, stuck
            $table->text('reason')->nullable();
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index('order_item_id');
            $table->index('payment_id');
            $table->index('requested_by');
            $table->index('decided_by');
            $table->index(['status', 'updated_at']);
        });

        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_amount CHECK (amount_minor > 0 AND (kind = 'unapplied' OR order_item_id IS NOT NULL))");
        // One live refund per course bought, and per unapplied payment (a rejected or cancelled one can be asked again).
        DB::statement("CREATE UNIQUE INDEX refunds_one_per_item ON refunds (order_item_id) WHERE kind = 'item' AND status NOT IN ('rejected', 'cancelled')");
        DB::statement("CREATE UNIQUE INDEX refunds_one_per_unapplied ON refunds (payment_id) WHERE kind = 'unapplied' AND status NOT IN ('rejected', 'cancelled')");

        Schema::create('refund_attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('refund_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('reference', 64)->unique(); // R-{refund id}-{number}, our reference at the gateway
            $table->string('gateway', 32);
            $table->string('status', 16)->index(); // processing | succeeded | failed | reversed
            $table->string('outcome', 16)->nullable(); // on success: completed (paid the refund) | overpaid (paid twice)
            $table->bigInteger('confirmed_amount_minor')->nullable();
            $table->bigInteger('fee_minor')->nullable();
            $table->string('gateway_reference', 128)->nullable();
            $table->string('operator_reference', 128)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message', 255)->nullable();
            $table->unsignedInteger('checks')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['refund_id', 'number']);
            $table->index(['status', 'last_checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_attempts');
        Schema::dropIfExists('refunds');

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('granted_at');
        });
    }
};
