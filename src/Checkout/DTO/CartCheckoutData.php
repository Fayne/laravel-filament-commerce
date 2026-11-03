<?php

declare(strict_types=1);

namespace Fayne\Commerce\Checkout\DTO;

use Illuminate\Support\Carbon;

final readonly class CartCheckoutData
{
    /**
     * @param  array{name: string, email?: string|null, phone?: string|null}  $buyer
     * @param  array<string, mixed>|null  $shippingAddress
     */
    public function __construct(
        public array $buyer,
        public ?array $shippingAddress,
        public int $shippingAmount,
        public ?int $customerId,
        public ?string $locale,
        public string $idempotencyKey,
        public Carbon $reservationExpiresAt,
    ) {}
}
