<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity (PRD-01, architecture §3.1): OTP challenges, registered devices, device-bound tokens, data exports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('phone_e164', 16);
            $table->string('purpose', 16); // login | change_phone
            $table->foreignUlid('user_id')->nullable()->constrained()->cascadeOnDelete(); // set for change_phone
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['phone_e164', 'purpose', 'created_at']);
        });

        // Mobile installs that may hold offline downloads (counted against the device limit).
        Schema::create('devices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 16); // android | ios
            $table->string('install_id', 64);
            $table->string('name')->nullable();
            $table->string('app_version', 32)->nullable();
            $table->text('push_token')->nullable();
            $table->timestamp('registered_at');
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'install_id']);
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignUlid('device_id')->nullable()->after('tokenable_id')->constrained()->cascadeOnDelete();
        });

        Schema::create('data_exports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending'); // pending | ready | failed
            $table->string('path')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exports');
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
        });
        Schema::dropIfExists('devices');
        Schema::dropIfExists('otp_challenges');
    }
};
