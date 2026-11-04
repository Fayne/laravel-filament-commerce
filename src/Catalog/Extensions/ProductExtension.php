<?php

declare(strict_types=1);

namespace Fayne\Commerce\Catalog\Extensions;

use Fayne\Commerce\Catalog\Models\Product;
use Illuminate\Support\Facades\DB;

abstract class ProductExtension
{
    abstract public function key(): string;

    public function fields(string $slot): array
    {
        return [];
    }

    public function tabs(): array
    {
        return [];
    }

    abstract public function rules(): array;

    public function fill(Product $product): array
    {
        $value = DB::table('commerce_product_extensions')->where('tenant_id', $product->tenant_id)
            ->where('product_id', $product->id)->where('extension_key', $this->key())->value('data');

        return $value === null ? [] : json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    public function save(Product $product, array $data): void
    {
        DB::table('commerce_product_extensions')->updateOrInsert(
            ['tenant_id' => $product->tenant_id, 'product_id' => $product->id, 'extension_key' => $this->key()],
            ['data' => json_encode($data, JSON_THROW_ON_ERROR)],
        );
    }
}
