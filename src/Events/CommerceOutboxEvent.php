<?php

declare(strict_types=1);

namespace Fayne\Commerce\Events;

final readonly class CommerceOutboxEvent
{
    public function __construct(public int $tenantId, public string $eventKey, public string $type, public array $payload) {}
}
