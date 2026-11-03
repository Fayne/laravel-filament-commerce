<?php

declare(strict_types=1);

namespace Fayne\Commerce\Returns\Enums;

enum RefundStatus: string
{
    case PENDING_REVIEW = 'pending_review';
    case PROCESSING = 'processing';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case REJECTED = 'rejected';
    case UNKNOWN = 'unknown';
}
