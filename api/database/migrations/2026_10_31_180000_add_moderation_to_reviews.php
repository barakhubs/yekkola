<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PRD-09 FR-10: staff hide a review (and may restore it); who, when and why stay on the row.
        Schema::table('reviews', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable();
            $table->foreignUlid('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('hidden_reason', 2000)->nullable();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropConstrainedForeignId('hidden_by');
            $table->dropColumn(['hidden_at', 'hidden_reason']);
        });
    }
};
