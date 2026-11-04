<?php

use Fayne\CMS\Support\ContentLocale;
use Fayne\Commerce\Catalog\Actions\SaveProduct;
use Fayne\Commerce\Catalog\Extensions\ProductExtension;
use Fayne\Commerce\Catalog\Extensions\ProductExtensions;
use Fayne\Commerce\Catalog\Models\Product;
use Fayne\Commerce\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(TestCase::class);

beforeEach(function (): void {
    (require __DIR__.'/../../database/migrations/2026_11_02_000001_create_commerce_catalog_tables.php')->up();
    config(['commerce.extensions.products' => [DemoProductExtension::class]]);
});

function extensionProductData(): array
{
    return ['type' => 'physical', 'status' => 'draft', 'translations' => [
        ContentLocale::default() => ['name' => 'Extension product'],
    ], 'extensions' => ['details' => ['origin' => 'Shanghai', 'unregistered' => 'discard']]];
}

test('extensions round trip preserve omitted state and exclude undeclared values', function (): void {
    $product = app(SaveProduct::class)->execute(null, extensionProductData());
    expect(app(ProductExtensions::class)->fill($product))->toBe(['details' => ['origin' => 'Shanghai']]);
    $data = extensionProductData();
    unset($data['extensions']);
    app(SaveProduct::class)->execute($product, $data);
    expect(app(ProductExtensions::class)->fill($product)['details']['origin'])->toBe('Shanghai');
    $data['extensions'] = ['details' => ['origin' => 'Beijing', 'tenant_id' => 999]];
    app(SaveProduct::class)->execute($product, $data);
    expect(app(ProductExtensions::class)->fill($product))->toBe(['details' => ['origin' => 'Beijing']])
        ->and($product->refresh()->tenant_id)->toBe(0);
});

test('invalid extension values roll back product and translation writes', function (): void {
    $data = extensionProductData();
    $data['extensions']['details']['origin'] = str_repeat('x', 101);
    expect(fn () => app(SaveProduct::class)->execute(null, $data))->toThrow(ValidationException::class);
    expect(Product::query()->count())->toBe(0)
        ->and(DB::table('commerce_product_translations')->count())->toBe(0)
        ->and(DB::table('commerce_product_extensions')->count())->toBe(0);
});

test('duplicate extension keys are rejected', function (): void {
    config(['commerce.extensions.products' => [DemoProductExtension::class, DemoProductExtension::class]]);
    expect(fn () => app(ProductExtensions::class))->toThrow(InvalidArgumentException::class);
});

test('saving a product object from another tenant is rejected', function (): void {
    $product = (new Product)->forceFill(['id' => 10, 'tenant_id' => 999]);
    expect(fn () => app(SaveProduct::class)->execute($product, extensionProductData()))->toThrow(ValidationException::class);
});

final class DemoProductExtension extends ProductExtension
{
    public function key(): string
    {
        return 'details';
    }

    public function rules(): array
    {
        return ['origin' => ['required', 'string', 'max:100']];
    }
}

test('required extension rules apply when omitted on creation', function (): void {
    $data = extensionProductData();
    unset($data['extensions']);
    expect(fn () => app(SaveProduct::class)->execute(null, $data))->toThrow(ValidationException::class);
    expect(Product::query()->count())->toBe(0);
});
