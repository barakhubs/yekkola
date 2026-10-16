<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A coupon use, recorded when its order is paid (or completed free). One per order.
 *
 * @property string $id
 * @property string $coupon_id
 * @property string $user_id
 * @property string $order_id
 */
final class CouponRedemption extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = [];
}
