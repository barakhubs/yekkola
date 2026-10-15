<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Professor onboarding (PRD-02, architecture §3.2): applications, profiles, KYC, terms acceptance.
 * Files (credentials, avatar, KYC images) live in the `media` table (spatie/laravel-medialibrary).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terms_acceptances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('document', 32); // professor_agreement | terms_of_service | privacy_policy
            $table->string('version', 32);
            $table->timestamp('accepted_at');
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'document', 'accepted_at']);
        });

        Schema::create('professor_applications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->index(); // draft | submitted | under_review | needs_info | approved | rejected | expired
            $table->string('full_name')->nullable();
            $table->jsonb('subjects')->default('[]');
            $table->jsonb('levels')->default('[]');
            $table->unsignedSmallInteger('years_experience')->nullable();
            $table->text('institutions')->nullable();
            $table->text('credentials')->nullable();
            $table->string('sample_url', 500)->nullable();
            $table->text('motivation')->nullable();
            $table->foreignUlid('province_id')->nullable()->constrained()->nullOnDelete();
            $table->string('terms_version', 32)->nullable();
            $table->timestamp('submitted_at')->nullable()->index();
            $table->foreignUlid('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('review_started_at')->nullable();
            $table->text('info_request_note')->nullable();
            $table->timestamp('info_requested_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('reviewer_id');
            $table->index('province_id');
            $table->index('decided_at');
        });

        // One open application per user (FR-02).
        DB::statement("CREATE UNIQUE INDEX professor_applications_one_open ON professor_applications (user_id) WHERE status IN ('draft','submitted','under_review','needs_info')");

        Schema::create('professor_profiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUlid('application_id')->nullable()->constrained('professor_applications')->nullOnDelete();
            $table->string('slug', 80)->unique();
            $table->timestamp('slug_changed_at')->nullable();
            $table->string('display_name');
            $table->string('headline', 120)->nullable();
            $table->text('bio')->nullable();
            $table->jsonb('subjects')->default('[]');
            $table->jsonb('links')->default('[]');
            $table->foreignUlid('province_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('active')->index(); // active | suspended
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->string('kyc_status', 16)->default('none')->index(); // none | pending | verified | rejected
            $table->timestamp('verified_at')->nullable();
            $table->unsignedSmallInteger('revenue_share_bps')->nullable(); // null = platform default
            $table->string('payout_rail', 16)->nullable();
            $table->text('payout_msisdn')->nullable(); // encrypted
            $table->string('payout_name')->nullable();
            $table->timestamp('payout_method_updated_at')->nullable();
            $table->timestamp('payout_hold_until')->nullable();
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('student_count')->default(0);
            $table->timestamps();

            $table->index('application_id');
            $table->index('province_id');
            $table->index('display_name');
            $table->index('student_count');
            $table->index('created_at');
        });

        Schema::create('kyc_submissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('professor_profile_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 16); // national_id | voter_card | passport
            $table->string('status', 16)->default('pending')->index(); // pending | verified | rejected
            $table->foreignUlid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index('professor_profile_id');
            $table->index('reviewed_by');
            $table->index('created_at');
        });

        // One pending identity check per professor (FR-08).
        DB::statement("CREATE UNIQUE INDEX kyc_submissions_one_pending ON kyc_submissions (professor_profile_id) WHERE status = 'pending'");
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_submissions');
        Schema::dropIfExists('professor_profiles');
        Schema::dropIfExists('professor_applications');
        Schema::dropIfExists('terms_acceptances');
    }
};
