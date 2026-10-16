<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Checkout core (PRD-05): orders, items, payments, the payment-event inbox, enrollments (PRD-06, the only
 * access check), and the double-entry ledger (PRD-08 FR-01). Ledger rows are append-only (DB triggers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('number', 20)->unique();
            $table->foreignUlid('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('beneficiary_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 24)->index(); // pending | awaiting_payment | paid | expired | cancelled | reversed | refunded | partially_refunded
            $table->char('currency', 3);
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->string('idempotency_key', 255);
            $table->timestamp('expires_at')->index();
            $table->timestamp('paid_at')->nullable();
            $table->ulid('paid_payment_id')->nullable();
            $table->string('flag', 32)->nullable()->index(); // needs staff attention: late_success, amount_mismatch, reversed, …
            $table->timestamps();

            $table->unique(['buyer_id', 'idempotency_key']);
            $table->index(['buyer_id', 'created_at']);
            $table->index('beneficiary_id');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete(); // snapshots the ledger relies on
            $table->foreignUlid('course_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('professor_profile_id')->constrained()->restrictOnDelete();
            $table->string('title_snapshot', 200);
            $table->bigInteger('list_price_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('price_minor');
            $table->string('discount_funded_by', 16)->nullable(); // platform | professor | shared
            $table->unsignedSmallInteger('revenue_share_bps'); // professor share, snapshot
            $table->char('currency', 3);
            $table->timestamps();

            $table->index('order_id');
            $table->index('course_id');
            $table->index('professor_profile_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary(); // our reference at the gateway
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 255);
            $table->string('gateway', 32);
            $table->string('rail', 16); // orange | airtel | mpesa
            $table->text('msisdn'); // encrypted
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->bigInteger('confirmed_amount_minor')->nullable();
            $table->bigInteger('fee_minor')->nullable();
            $table->string('status', 16)->index(); // initiated | pending | succeeded | failed | expired | reversed
            $table->string('gateway_reference', 128)->nullable();
            $table->string('operator_reference', 128)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message', 255)->nullable();
            $table->string('flag', 32)->nullable()->index();
            $table->text('last_result')->nullable(); // encrypted: last gateway answer, for support
            $table->unsignedInteger('checks')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'idempotency_key']);
            $table->unique(['gateway', 'gateway_reference']);
            $table->index(['status', 'last_checked_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('payment_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('gateway', 32);
            $table->string('event_id', 191);
            $table->string('kind', 16); // collection | disbursement
            $table->string('reference', 64)->index();
            $table->text('payload'); // encrypted: raw bodies can carry payer numbers and names
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('error', 255)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();

            $table->unique(['gateway', 'event_id']);
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('course_id')->constrained()->restrictOnDelete();
            $table->string('source', 16); // purchase | free | coupon | bundle | admin
            $table->foreignUlid('order_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 16); // active | revoked
            $table->string('revoked_reason', 64)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('enrolled_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedTinyInteger('progress_pct')->default(0);
            $table->string('last_lesson_uid', 26)->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'course_id']);
            $table->index(['course_id', 'status']);
            $table->index('order_item_id');
        });

        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('type', 32); // gateway_clearing | gateway_fees | platform_revenue | professor_held | professor_available | professor_paid_out | refunds_payable | discounts_platform
            $table->string('owner_type', 16); // platform | professor
            $table->ulid('owner_id')->nullable();
            $table->char('currency', 3);
            $table->timestamps();

            $table->unique(['type', 'owner_type', 'owner_id', 'currency'])->nullsNotDistinct();
            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('type', 24); // sale | sale_reversal | release | refund | payout | payout_failed | adjustment
            $table->string('reference_type', 32);
            $table->ulid('reference_id');
            $table->string('memo', 255)->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            // One posting of each kind per reference: a replayed handler can never post twice.
            $table->unique(['type', 'reference_type', 'reference_id']);
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('ledger_transaction_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('ledger_account_id')->constrained()->restrictOnDelete();
            $table->string('direction', 6); // debit | credit
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->timestamp('created_at');

            $table->index('ledger_transaction_id');
            $table->index(['ledger_account_id', 'created_at']);
        });

        // Snapshot sanity: a bad snapshot must fail at order creation, not after the money is collected.
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amounts CHECK (subtotal_minor >= 0 AND discount_minor >= 0 AND total_minor = subtotal_minor - discount_minor AND total_minor >= 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_amounts CHECK (list_price_minor >= 0 AND discount_minor >= 0 AND price_minor = list_price_minor - discount_minor AND price_minor >= 0 AND revenue_share_bps BETWEEN 0 AND 10000)');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_positive CHECK (amount_minor > 0 AND direction IN ('debit', 'credit'))");

        // Append-only (PRD-08 BR-02): corrections are new transactions, never edits.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Ledger rows are append-only (%.%)', TG_TABLE_NAME, TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER ledger_transactions_append_only BEFORE UPDATE OR DELETE ON ledger_transactions
                FOR EACH ROW EXECUTE FUNCTION ledger_append_only();
            CREATE TRIGGER ledger_entries_append_only BEFORE UPDATE OR DELETE ON ledger_entries
                FOR EACH ROW EXECUTE FUNCTION ledger_append_only();
            CREATE TRIGGER ledger_transactions_no_truncate BEFORE TRUNCATE ON ledger_transactions
                FOR EACH STATEMENT EXECUTE FUNCTION ledger_append_only();
            CREATE TRIGGER ledger_entries_no_truncate BEFORE TRUNCATE ON ledger_entries
                FOR EACH STATEMENT EXECUTE FUNCTION ledger_append_only();

            -- An account's identity never changes (entries would silently move with it).
            CREATE OR REPLACE FUNCTION ledger_account_immutable() RETURNS trigger AS $$
            BEGIN
                IF NEW.type IS DISTINCT FROM OLD.type OR NEW.owner_type IS DISTINCT FROM OLD.owner_type
                    OR NEW.owner_id IS DISTINCT FROM OLD.owner_id OR NEW.currency IS DISTINCT FROM OLD.currency THEN
                    RAISE EXCEPTION 'Ledger account identity is immutable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER ledger_accounts_immutable BEFORE UPDATE ON ledger_accounts
                FOR EACH ROW EXECUTE FUNCTION ledger_account_immutable();
            CREATE TRIGGER ledger_accounts_no_delete BEFORE DELETE OR TRUNCATE ON ledger_accounts
                FOR EACH STATEMENT EXECUTE FUNCTION ledger_append_only();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS ledger_entries, ledger_transactions, ledger_accounts; DROP FUNCTION IF EXISTS ledger_append_only(); DROP FUNCTION IF EXISTS ledger_account_immutable();');

        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_transactions');
        Schema::dropIfExists('ledger_accounts');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
