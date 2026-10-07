<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log (spatie/laravel-activitylog). Subject/causer ids are strings so the log can point at
 * ULID models as well as the permission package's integer role/permission ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->string('subject_type')->nullable();
            $table->string('subject_id', 36)->nullable();
            $table->string('event')->nullable();
            $table->string('causer_type')->nullable();
            $table->string('causer_id', 36)->nullable();
            $table->jsonb('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['causer_type', 'causer_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
