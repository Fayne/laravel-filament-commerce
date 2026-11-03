<?php

declare(strict_types=1);

namespace Fayne\Commerce\Pricing\DTO;

use Fayne\Promotion\DTO\PromotionResult;

final readonly class CommerceQuote
{
    /** @param list<array{line_id: string, variant_id: int, sku: string, name: string, quantity: int, unit_amount: int, line_amount: int}> $lines */
    public function __construct(
        public int $tenantId,
        public string $currency,
        public array $lines,
        public int $subtotal,
        public int $shipping,
        public PromotionResult $promotion,
        public int $payable,
        public ?string $couponCode = null,
        public ?int $customerId = null,
        public string $locale = 'zh_CN',
    ) {}
}
