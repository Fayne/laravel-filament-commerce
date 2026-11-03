<?php

declare(strict_types=1);

namespace Fayne\Commerce\Catalog\Enums;

enum ProductType: string
{
    case PHYSICAL = 'physical';
    case DIGITAL = 'digital';
    case SERVICE = 'service';
}
