<?php

declare(strict_types=1);

namespace Fayne\Commerce\Catalog\Extensions;

use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

final class SkuExtensions
{
    private array $extensions = [];

    public function __construct()
    {
        foreach (config('commerce.extensions.skus', []) as $class) {
            if (! is_string($class) || ! is_subclass_of($class, SkuExtension::class)) {
                throw new InvalidArgumentException('SKU extensions must extend SkuExtension.');
            }
            $extension = app($class);
            $key = $extension->key();
            if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) || isset($this->extensions[$key])) {
                throw new InvalidArgumentException('SKU extension keys must be valid and unique.');
            }
            $this->extensions[$key] = $extension;
        }
    }

    public function fields(): array
    {
        return array_merge([], ...array_map(fn ($extension): array => $extension->fields(), $this->extensions));
    }

    public function validate(array $submitted, array $stored): array
    {
        foreach ($this->extensions as $key => $extension) {
            $values = array_key_exists($key, $submitted) ? $submitted[$key] : ($stored[$key] ?? []);
            $rules = [];
            foreach ($extension->rules() as $field => $rule) {
                $rules['extension_data.'.$key.'.'.$field] = $rule;
            }
            $validated = Validator::make(['extension_data' => [$key => $values]], $rules)->validated();
            $stored[$key] = $validated['extension_data'][$key] ?? [];
        }

        return $stored;
    }
}
