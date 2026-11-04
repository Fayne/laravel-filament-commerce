<?php

declare(strict_types=1);

namespace Fayne\Commerce\Catalog\Extensions;

abstract class SkuExtension
{
    abstract public function key(): string;

    abstract public function fields(): array;

    abstract public function rules(): array;
}
