<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger transaction types outgrew 24 characters (`unapplied_receipt_reversal`); widen before refunds add more.
 * Column type changes are DDL, so the append-only row triggers don't apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_transactions', function (Blueprint $table) {
            $table->string('type', 40)->change();
        });
    }

    public function down(): void
    {
        Schema::table('ledger_transactions', function (Blueprint $table) {
            $table->string('type', 24)->change();
        });
    }
};
