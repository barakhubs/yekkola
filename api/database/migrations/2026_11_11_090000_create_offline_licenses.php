<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline downloads granted to registered devices (PRD-07 FR-07, BR-02, BR-04): one row per device and lesson,
 * renewed in place. Drives entitlement sync (revoked or expired → the app deletes the download) and audits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_licenses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('device_id')->constrained()->restrictOnDelete();
            $table->string('lesson_uid', 26);
            $table->foreignUlid('media_asset_id')->constrained()->restrictOnDelete();
            $table->string('rendition', 16);
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'lesson_uid']);
            $table->index(['user_id', 'revoked_at']);
            $table->index('media_asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_licenses');
    }
};
