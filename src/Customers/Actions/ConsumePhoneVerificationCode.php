<?php

declare(strict_types=1);

namespace Fayne\Commerce\Customers\Actions;

use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Customers\Models\PhoneVerificationCode;
use Fayne\Commerce\Customers\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final readonly class ConsumePhoneVerificationCode
{
    public function __construct(private TenantContext $tenant) {}

    public function execute(string $phone, string $code): string
    {
        $tenantId = $this->tenant->requireId();
        $phone = PhoneNumber::normalize($phone);
        if (preg_match('/^[0-9]{6}$/', $code) !== 1) {
            throw ValidationException::withMessages(['code' => __('commerce::customers.invalid_code')]);
        }

        $rateKey = 'commerce:customer:sms:verify:'.$tenantId.':'.hash('sha256', $phone);
        if (RateLimiter::tooManyAttempts($rateKey, 20)) {
            throw ValidationException::withMessages(['code' => __('commerce::customers.verify_limit')]);
        }
        RateLimiter::hit($rateKey, 600);

        $valid = DB::transaction(function () use ($phone, $code): bool {
            $challenge = PhoneVerificationCode::query()->where('phone', $phone)->whereNull('consumed_at')
                ->where('expires_at', '>', now())->latest('id')->lockForUpdate()->first();
            if (! $challenge || $challenge->attempts >= 5) {
                return false;
            }
            if (! Hash::check($code, $challenge->code_hash)) {
                $attempts = $challenge->attempts + 1;
                $challenge->update(['attempts' => $attempts, 'consumed_at' => $attempts >= 5 ? now() : null]);

                return false;
            }

            $challenge->update(['consumed_at' => now()]);

            return true;
        }, attempts: 3);

        if (! $valid) {
            throw ValidationException::withMessages(['code' => __('commerce::customers.invalid_code')]);
        }

        return $phone;
    }
}
