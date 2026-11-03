<?php

declare(strict_types=1);

namespace Fayne\Commerce\Customers\Support;

use Illuminate\Validation\ValidationException;

final class PhoneNumber
{
    public static function normalize(string $phone): string
    {
        $phone = trim($phone);
        if (preg_match('/^1[3-9][0-9]{9}$/', $phone) === 1) {
            return '+86'.$phone;
        }
        if (preg_match('/^\+[1-9][0-9]{5,30}$/', $phone) === 1) {
            return $phone;
        }

        throw ValidationException::withMessages(['phone' => __('commerce::customers.invalid_phone')]);
    }
}
