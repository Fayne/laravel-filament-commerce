<?php

declare(strict_types=1);

namespace Fayne\Commerce\Inventory\Enums;

enum StockReservationStatus: string
{
    case RESERVED = 'reserved';
    case COMMITTED = 'committed';
    case RELEASED = 'released';
}
