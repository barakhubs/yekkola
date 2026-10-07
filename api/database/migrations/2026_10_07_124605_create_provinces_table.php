<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The 26 DRC provinces (ISO 3166-2:CD). Reference data, seeded by ProvinceSeeder.
        Schema::create('provinces', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 5)->unique();
            $table->string('name');
            $table->timestamps();
        });

        // Optional location on accounts — for filters and analytics only, never a requirement.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUlid('province_id')->nullable()->after('locale')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('province_id');
        });

        Schema::dropIfExists('provinces');
    }
};
