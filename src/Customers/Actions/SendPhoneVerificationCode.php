<?php

declare(strict_types=1);

namespace Fayne\Commerce\Customers\Actions;

use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Customers\Contracts\SmsCodeSender;
use Fayne\Commerce\Customers\Models\PhoneVerificationCode;
use Fayne\Commerce\Customers\Support\PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class SendPhoneVerificationCode
{
    public function __construct(private TenantContext $tenant, private SmsCodeSender $sender) {}

    public function execute(string $phone): void
    {
        $tenantId = $this->tenant->requireId();
        $phone = PhoneNumber::normalize($phone);

        $phoneHash = hash('sha256', $phone);
        $rateKey = "commerce:customer:sms:send:{$tenantId}:{$phoneHash}";
        if (RateLimiter::tooManyAttempts($rateKey, 10)) {
            throw ValidationException::withMessages(['phone' => __('commerce::customers.send_limit')]);
        }
        if (! Cache::add("{$rateKey}:cooldown", true, now()->addSeconds(60))) {
            throw ValidationException::withMessages(['phone' => __('commerce::customers.cooldown')]);
        }
        RateLimiter::hit($rateKey, 86400);

        $code = (string) random_int(100000, 999999);
        DB::transaction(function () use ($phone, $code): void {
            PhoneVerificationCode::query()->where('phone', $phone)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            PhoneVerificationCode::query()->create([
                'phone' => $phone,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(5),
                'attempts' => 0,
            ]);
        });

        try {
            $this->sender->send($phone, $code, $tenantId);
        } catch (Throwable $exception) {
            Log::warning('Commerce customer verification SMS delivery failed.', ['exception_class' => $exception::class]);
            throw ValidationException::withMessages(['phone' => __('commerce::customers.send_failed')]);
        }
    }
}
