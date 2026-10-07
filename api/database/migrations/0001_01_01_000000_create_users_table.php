<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Phone (E.164) is the identity; sign-in is by OTP, so there is no password column.
        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // 40 chars: anonymised accounts get a "deleted:<ulid>" placeholder to free the real number.
            $table->string('phone_e164', 40)->unique();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('locale', 2)->default('fr');
            $table->string('city')->nullable();
            $table->string('status', 16)->default('active')->index();
            // Bumped to sign the user out everywhere (phone change, suspension, ban, admin force sign-out).
            $table->unsignedInteger('auth_epoch')->default(0);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('deletion_requested_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUlid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
