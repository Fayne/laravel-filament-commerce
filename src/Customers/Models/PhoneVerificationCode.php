<?php

declare(strict_types=1);

namespace Fayne\Commerce\Customers\Models;

use Fayne\CMS\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class PhoneVerificationCode extends Model
{
    use BelongsToTenant;

    protected $table = 'commerce_customer_phone_codes';

    protected $fillable = ['phone', 'code_hash', 'expires_at', 'consumed_at', 'attempts'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime', 'attempts' => 'integer'];
    }
}
