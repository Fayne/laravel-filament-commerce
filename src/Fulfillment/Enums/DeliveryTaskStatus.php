<?php

declare(strict_types=1);

namespace Fayne\Commerce\Fulfillment\Enums;

enum DeliveryTaskStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case UNKNOWN = 'unknown';
}
