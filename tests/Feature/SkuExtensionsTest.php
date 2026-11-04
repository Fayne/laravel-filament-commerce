<?php

use Fayne\Commerce\Catalog\Extensions\SkuExtension;
use Fayne\Commerce\Catalog\Extensions\SkuExtensions;
use Fayne\Commerce\Catalog\Models\Product;
use Fayne\Commerce\Catalog\Models\ProductVariant;
use Fayne\Commerce\Tests\TestCase;
use Filament\Forms\Components\TextInput;
use Illuminate\Validation\ValidationException;

uses(TestCase::class);

beforeEach(function (): void {
    (require __DIR__.'/../../database/migrations/2026_11_02_000001_create_commerce_catalog_tables.php')->up();
    config(['commerce.extensions.skus' => [DemoSkuExtension::class]]);
});

function demoExtendedSku(string $sku, string $barcode): ProductVariant
{
    $product = Product::query()->create(['type' => 'physical', 'status' => 'draft']);

    return $product->variants()->create([
        'sku' => $sku, 'price_amount' => 100, 'is_active' => true,
        'extension_data' => ['logistics' => ['barcode' => $barcode, 'injected' => 'discard']],
    ]);
}

test('SKU extensions round trip independently and preserve omitted namespaces', function (): void {
    $first = demoExtendedSku('FIRST', '111');
    $second = demoExtendedSku('SECOND', '222');
    $first->update(['price_amount' => 200]);
    expect($first->refresh()->extension_data)->toBe(['logistics' => ['barcode' => '111']])
        ->and($second->refresh()->extension_data)->toBe(['logistics' => ['barcode' => '222']]);
    $first->update(['extension_data' => []]);
    expect($first->refresh()->extension_data['logistics']['barcode'])->toBe('111');
});

test('invalid SKU extension changes never reach the database', function (): void {
    $sku = demoExtendedSku('FIRST', '111');
    expect(fn () => $sku->update(['price_amount' => 999, 'extension_data' => ['logistics' => ['barcode' => '']]]))
        ->toThrow(ValidationException::class);
    expect($sku->refresh()->price_amount)->toBe(100)
        ->and($sku->extension_data['logistics']['barcode'])->toBe('111');
});

test('required SKU fields are validated on creation without extension data', function (): void {
    $product = Product::query()->create(['type' => 'physical', 'status' => 'draft']);
    expect(fn () => $product->variants()->create(['sku' => 'INVALID', 'price_amount' => 100]))
        ->toThrow(ValidationException::class);
    expect(ProductVariant::query()->count())->toBe(0);
});

test('duplicate SKU extension keys are rejected', function (): void {
    config(['commerce.extensions.skus' => [DemoSkuExtension::class, DemoSkuExtension::class]]);
    expect(fn () => app(SkuExtensions::class))->toThrow(InvalidArgumentException::class);
});

test('SKU objects from another tenant cannot be saved', function (): void {
    $sku = demoExtendedSku('FIRST', '111');
    $sku->tenant_id = 99;
    expect(fn () => $sku->save())->toThrow(ValidationException::class);
    expect($sku->refresh()->tenant_id)->toBe(0);
});

final class DemoSkuExtension extends SkuExtension
{
    public function key(): string
    {
        return 'logistics';
    }

    public function fields(): array
    {
        return [TextInput::make('extension_data.logistics.barcode')->required()];
    }

    public function rules(): array
    {
        return ['barcode' => ['required', 'string', 'max:32']];
    }
}
