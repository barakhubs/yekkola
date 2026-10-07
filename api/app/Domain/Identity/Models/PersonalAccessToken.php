<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum token with a ULID primary key (registered in AppServiceProvider).
 */
// Not final: Sanctum::actingAs() mocks the token model in tests.
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use HasUlids;
}
