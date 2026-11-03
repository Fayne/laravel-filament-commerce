<?php

declare(strict_types=1);

namespace Fayne\Commerce\Customers\Models;

use Fayne\CMS\Support\Concerns\BelongsToTenant;
use Fayne\Commerce\Customers\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\ValidationException;

final class Customer extends Authenticatable
{
    use BelongsToTenant;
    use Notifiable;

    protected $table = 'commerce_customers';

    protected $fillable = ['name'];

    protected $hidden = ['email', 'phone', 'password', 'remember_token', 'wechat_appid', 'wechat_openid'];

    protected static function booted(): void
    {
        self::addGlobalScope('customer_active', fn (Builder $query) => $query->where($query->getModel()->qualifyColumn('is_active'), true));
        self::saving(function (self $customer): void {
            if (filled($customer->phone)) {
                $customer->phone = PhoneNumber::normalize($customer->phone);
            }
            if (filled($customer->wechat_appid) !== filled($customer->wechat_openid) || strlen((string) $customer->wechat_appid) > 64 || strlen((string) $customer->wechat_openid) > 128) {
                throw ValidationException::withMessages(['wechat' => __('commerce::customers.mini_unverified')]);
            }
        });
    }

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_active' => 'boolean', 'email_verified_at' => 'immutable_datetime', 'phone_verified_at' => 'immutable_datetime'];
    }
}
