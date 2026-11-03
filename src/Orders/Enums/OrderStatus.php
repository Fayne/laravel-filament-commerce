<?php

declare(strict_types=1);

namespace Fayne\Commerce\Orders\Enums;

enum OrderStatus: string
{
    case AWAITING_PAYMENT = 'awaiting_payment';
    case CONFIRMED = 'confirmed';
    case PAYMENT_REVIEW = 'payment_review';
    case CANCELLED = 'cancelled';
}
