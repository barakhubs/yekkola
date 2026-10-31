<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Staff may also sign in to the back office with email + password (owner decision, 2026-10-31).
        // Students and professors keep phone + SMS code only.
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->boolean('must_change_password')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['password', 'password_changed_at', 'must_change_password']);
        });
    }
};
