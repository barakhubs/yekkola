<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Accent-insensitive catalogue search when the search service is down (PRD-04 §7): "algebre" finds
 * "Algèbre". `unaccent` is a trusted extension (PostgreSQL 13+), so the app's database user can enable it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
    }

    public function down(): void
    {
        // Left installed: other databases objects may come to depend on it.
    }
};
