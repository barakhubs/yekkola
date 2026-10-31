<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PRD-09 FR-08, PRD-07 FR-15: a taken-down course is unpublished and its enrolled students lose access too.
        Schema::table('courses', function (Blueprint $table) {
            $table->timestamp('taken_down_at')->nullable()->index();
            $table->string('takedown_reason', 2000)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['taken_down_at', 'takedown_reason']);
        });
    }
};
