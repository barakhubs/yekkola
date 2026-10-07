<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum token with a ULID primary key (registered in AppServiceProvider).
 */
final class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use HasUlids;
}
