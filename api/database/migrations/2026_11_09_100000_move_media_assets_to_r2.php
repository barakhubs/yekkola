<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Media on Cloudflare R2 with encrypted HLS (owner decision 2026-11-09): our own object keys, renditions and
 * AES-128 key replace the Mux asset and playback ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropUnique(['provider_asset_id']);
            $table->dropColumn(['provider_asset_id', 'playback_id']);
        });

        Schema::table('media_assets', function (Blueprint $table) {
            $table->string('source_key')->nullable()->after('provider_upload_id');
            $table->unsignedBigInteger('size_bytes')->nullable()->after('source_key');
            $table->string('output_prefix')->nullable()->after('size_bytes');
            // [{name, bandwidth, size_bytes, width, height}], highest first
            $table->jsonb('renditions')->nullable()->after('output_prefix');
            // AES-128 key, encrypted with the app key (never stored in R2)
            $table->text('encryption_key')->nullable()->after('renditions');
            $table->boolean('has_poster')->default(false)->after('encryption_key');
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropColumn(['source_key', 'size_bytes', 'output_prefix', 'renditions', 'encryption_key', 'has_poster']);
        });

        Schema::table('media_assets', function (Blueprint $table) {
            $table->string('provider_asset_id')->nullable()->unique();
            $table->string('playback_id')->nullable();
        });
    }
};
