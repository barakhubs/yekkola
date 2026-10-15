<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue (PRD-04): manual ordering of featured courses (BR-03) and the student wishlist (FR-08).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('is_featured');
            $table->unsignedSmallInteger('featured_position')->nullable()->index(); // null = not featured

            // Catalogue sorts (FR-02)
            $table->index(['status', 'published_at']);
            $table->index(['status', 'enrollment_count']);
            $table->index(['status', 'rating_avg']);
            $table->rawIndex('audience jsonb_path_ops', 'courses_audience_gin')->algorithm('gin');
        });

        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('course_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->primary(['user_id', 'course_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_items');

        Schema::table('courses', function (Blueprint $table) {
            $table->dropIndex(['status', 'published_at']);
            $table->dropIndex(['status', 'enrollment_count']);
            $table->dropIndex(['status', 'rating_avg']);
            $table->dropIndex('courses_audience_gin');
            $table->dropColumn('featured_position');
            $table->boolean('is_featured')->default(false);
        });
    }
};
