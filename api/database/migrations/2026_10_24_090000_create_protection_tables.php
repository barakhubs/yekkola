<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content protection (PRD-07): web stream sessions for the concurrent-stream limit (FR-05), and the cache of
 * per-student stamped PDFs (FR-12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stream_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->string('lesson_uid', 26);
            $table->foreignUlid('media_asset_id')->constrained()->restrictOnDelete();
            $table->timestamp('last_heartbeat_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason', 16)->nullable(); // replaced
            $table->timestamps();

            $table->index(['user_id', 'ended_at', 'last_heartbeat_at']);
            $table->index('media_asset_id');
        });

        Schema::create('document_stamps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            // The source PDF (medialibrary row): a replaced document gets new stamps.
            $table->foreignId('source_media_id')->constrained('media')->cascadeOnDelete();
            $table->string('status', 16); // pending | ready | failed
            $table->string('path')->nullable(); // private disk
            $table->string('error')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'source_media_id']);
            $table->index('source_media_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_stamps');
        Schema::dropIfExists('stream_sessions');
    }
};
