<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R2 (S3) multipart upload ids are long opaque strings (~350 characters seen), more than varchar(255).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->text('provider_upload_id')->nullable()->change();
            $table->string('source_key', 1024)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->string('provider_upload_id')->nullable()->change();
            $table->string('source_key')->nullable()->change();
        });
    }
};
