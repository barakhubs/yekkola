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
        // PRD-08 FR-06–FR-08: one batch per currency and run; one payout line per professor in it.
        Schema::create('payout_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('status', 24)->index(); // draft | processing | completed | partially_failed
            $table->string('currency', 3);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->restrictOnDelete(); // null: scheduled
            $table->foreignUlid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
        // At most one open (draft or processing) batch per currency.
        DB::statement("CREATE UNIQUE INDEX payout_batches_one_open_per_currency ON payout_batches (currency) WHERE status IN ('draft', 'processing')");

        Schema::create('payouts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('payout_batch_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('professor_profile_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('rail', 16);
            $table->text('msisdn'); // encrypted
            $table->string('name', 120);
            $table->string('reference', 64)->unique(); // P-{id}, our reference at the gateway
            $table->string('status', 16)->index(); // pending | excluded | processing | paid | failed
            $table->string('gateway', 32)->nullable();
            $table->string('gateway_reference', 128)->nullable();
            $table->string('operator_reference', 128)->nullable();
            $table->bigInteger('fee_minor')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message', 255)->nullable();
            $table->string('excluded_reason', 500)->nullable();
            $table->unsignedInteger('checks')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index('payout_batch_id');
            $table->index(['professor_profile_id', 'currency']);
            $table->index(['status', 'last_checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('payout_batches');
    }
};
