<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coupons (PRD-05 FR-11, FR-12) and bundles (FR-13). Redemptions are recorded on payment success; open
 * orders using a coupon count as reservations so limits hold under concurrent checkouts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 40)->unique(); // stored upper-case; matched case-insensitively
            $table->string('scope', 16); // platform | professor | course
            $table->foreignUlid('professor_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('course_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('discount_type', 8); // percent | fixed
            $table->unsignedInteger('value'); // percent: 1–100; fixed: minor units of `currency`
            $table->char('currency', 3)->nullable(); // fixed only
            $table->string('funded_by', 16); // platform | professor | shared
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('per_user_limit')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('professor_profile_id');
            $table->index('course_id');
        });

        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_value CHECK ((discount_type = 'percent' AND value BETWEEN 1 AND 100 AND currency IS NULL) OR (discount_type = 'fixed' AND value > 0 AND currency IS NOT NULL))");

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('coupon_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamp('created_at');

            $table->index(['coupon_id', 'user_id']);
        });

        Schema::create('bundles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('professor_profile_id')->nullable()->constrained()->restrictOnDelete(); // null = platform bundle
            $table->string('slug', 100)->unique();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->bigInteger('price_minor');
            $table->char('currency', 3);
            $table->string('status', 16)->index(); // draft | active | archived
            $table->timestamps();

            $table->index('professor_profile_id');
        });

        Schema::create('bundle_courses', function (Blueprint $table) {
            $table->foreignUlid('bundle_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('course_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');

            $table->primary(['bundle_id', 'course_id']);
            $table->index('course_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignUlid('coupon_id')->nullable()->after('total_minor')->constrained()->restrictOnDelete();
            $table->foreignUlid('bundle_id')->nullable()->after('coupon_id')->constrained()->restrictOnDelete();
            $table->index(['coupon_id', 'status']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignUlid('bundle_id')->nullable()->after('course_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bundle_id');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['coupon_id', 'status']);
            $table->dropConstrainedForeignId('bundle_id');
            $table->dropConstrainedForeignId('coupon_id');
        });
        Schema::dropIfExists('bundle_courses');
        Schema::dropIfExists('bundles');
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
    }
};
