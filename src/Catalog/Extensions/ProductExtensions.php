<?php

declare(strict_types=1);

namespace Fayne\Commerce\Catalog\Extensions;

use Fayne\Commerce\Catalog\Models\Product;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

final class ProductExtensions
{
    private array $extensions = [];

    public function __construct()
    {
        foreach (config('commerce.extensions.products', []) as $class) {
            if (! is_string($class) || ! is_subclass_of($class, ProductExtension::class)) {
                throw new InvalidArgumentException('Product extensions must extend ProductExtension.');
            }
            $extension = app($class);
            $key = $extension->key();
            if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) || isset($this->extensions[$key])) {
                throw new InvalidArgumentException('Product extension keys must be valid and unique.');
            }
            $this->extensions[$key] = $extension;
        }
    }

    public function fields(string $slot): array
    {
        return array_merge([], ...array_map(fn ($extension): array => $extension->fields($slot), $this->extensions));
    }

    public function tabs(): array
    {
        return array_merge([], ...array_map(fn ($extension): array => $extension->tabs(), $this->extensions));
    }

    public function fill(Product $product): array
    {
        return array_map(fn ($extension): array => $extension->fill($product), $this->extensions);
    }

    public function save(Product $product, array $data): void
    {
        foreach ($this->extensions as $key => $extension) {
            $submitted = array_key_exists($key, $data);
            $values = $submitted ? $data[$key] : $extension->fill($product);
            $rules = [];
            foreach ($extension->rules() as $field => $rule) {
                $rules['extensions.'.$key.'.'.$field] = $rule;
            }
            $validated = Validator::make(['extensions' => [$key => $values]], $rules)->validated();
            if ($submitted) {
                $extension->save($product, $validated['extensions'][$key] ?? []);
            }
        }
    }
}
