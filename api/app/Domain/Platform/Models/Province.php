<?php

declare(strict_types=1);

namespace App\Domain\Platform\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A DRC province (ISO 3166-2:CD code). Listed alphabetically — no province is privileged.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 */
#[Fillable(['code', 'name'])]
final class Province extends Model
{
    use HasUlids;

    /**
     * @param  Builder<self>  $query
     */
    public function scopeAlphabetical(Builder $query): void
    {
        $query->orderBy('name');
    }
}
