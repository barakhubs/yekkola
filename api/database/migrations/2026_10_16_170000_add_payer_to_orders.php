<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parent payer (PRD-05 FR-14): an order can be bought for another student, or by a parent with no account
 * (guest checkout: no buyer, a one-time access token, and the payer's number).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->ulid('buyer_id')->nullable()->change();
            $table->text('payer_msisdn')->nullable()->after('beneficiary_id'); // encrypted
            $table->char('guest_token_hash', 64)->nullable()->unique()->after('payer_msisdn');
        });

        // Guests have no buyer: their idempotency keys are scoped to the student.
        DB::statement('CREATE UNIQUE INDEX orders_guest_idempotency ON orders (beneficiary_id, idempotency_key) WHERE buyer_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS orders_guest_idempotency');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['guest_token_hash']);
            $table->dropColumn(['payer_msisdn', 'guest_token_hash']);
            $table->ulid('buyer_id')->nullable(false)->change();
        });
    }
};
