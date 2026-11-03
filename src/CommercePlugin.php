<?php

declare(strict_types=1);

namespace Fayne\Commerce;

use Fayne\Commerce\Filament\Resources\OrderResource;
use Fayne\Commerce\Filament\Resources\ProductResource;
use Fayne\Commerce\Filament\Resources\RefundRequestResource;
use Fayne\Commerce\Filament\Resources\StockLevelResource;
use Fayne\Commerce\Filament\Resources\StockMovementResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

final class CommercePlugin implements Plugin
{
    public static function make(): static
    {
        return new self;
    }

    public function getId(): string
    {
        return 'commerce';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([ProductResource::class, OrderResource::class, RefundRequestResource::class, StockLevelResource::class, StockMovementResource::class]);
    }

    public function boot(Panel $panel): void {}
}
