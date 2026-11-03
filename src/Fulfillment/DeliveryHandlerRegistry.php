<?php

declare(strict_types=1);

namespace Fayne\Commerce\Fulfillment;

use Fayne\Commerce\Fulfillment\Contracts\DeliveryHandler;
use Fayne\Commerce\Fulfillment\Contracts\DeliveryStatusResolver;
use InvalidArgumentException;

final class DeliveryHandlerRegistry
{
    /** @var array<string, class-string<DeliveryHandler>> */
    private array $handlers = [];

    /** @var array<string, class-string<DeliveryStatusResolver>> */
    private array $statusResolvers = [];

    /** @param class-string<DeliveryHandler> $handlerClass */
    public function register(string $key, string $handlerClass): void
    {
        if (trim($key) === '' || ! is_subclass_of($handlerClass, DeliveryHandler::class)) {
            throw new InvalidArgumentException('A stable key and a DeliveryHandler implementation are required.');
        }

        $this->handlers[$key] = $handlerClass;
    }

    public function resolve(string $key): DeliveryHandler
    {
        $handlerClass = $this->handlers[$key] ?? throw new InvalidArgumentException("No delivery handler is registered for [{$key}].");

        return app($handlerClass);
    }

    /** @param class-string<DeliveryStatusResolver> $resolverClass */
    public function registerStatusResolver(string $key, string $resolverClass): void
    {
        if (trim($key) === '' || ! is_subclass_of($resolverClass, DeliveryStatusResolver::class)) {
            throw new InvalidArgumentException('A stable key and a DeliveryStatusResolver implementation are required.');
        }

        $this->statusResolvers[$key] = $resolverClass;
    }

    public function resolveStatusResolver(string $key): ?DeliveryStatusResolver
    {
        $resolverClass = $this->statusResolvers[$key] ?? null;

        return $resolverClass ? app($resolverClass) : null;
    }
}
