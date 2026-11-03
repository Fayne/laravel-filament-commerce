<?php

declare(strict_types=1);

namespace Fayne\Commerce\Catalog\Actions;

use Fayne\CMS\Support\ContentLocale;
use Fayne\Commerce\Catalog\Enums\ProductType;
use Fayne\Commerce\Catalog\Models\Product;
use Fayne\Commerce\Catalog\Models\ProductTranslation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveProduct
{
    /** @param array<string, mixed> $data */
    public function execute(?Product $product, array $data): Product
    {
        $locales = ContentLocale::enabled() ? array_keys(ContentLocale::supported()) : [ContentLocale::default()];
        $translations = $data['translations'] ?? [];
        if (! isset($data['type']) || ProductType::tryFrom((string) $data['type']) === null) {
            throw ValidationException::withMessages(['type' => __('commerce::products.invalid_type')]);
        }
        if (! in_array($data['status'] ?? 'draft', ['draft', 'active', 'archived'], true)) {
            throw ValidationException::withMessages(['status' => __('commerce::products.invalid_status')]);
        }
        if (in_array($data['type'], [ProductType::DIGITAL->value, ProductType::SERVICE->value], true) && trim((string) ($data['delivery_handler'] ?? '')) === '') {
            throw ValidationException::withMessages(['delivery_handler' => __('commerce::products.handler_required')]);
        }
        $defaultLocale = ContentLocale::default();

        if (! isset($translations[$defaultLocale]['name']) || trim((string) $translations[$defaultLocale]['name']) === '') {
            throw ValidationException::withMessages(['translations.'.$defaultLocale.'.name' => __('commerce::products.default_name_required')]);
        }

        foreach (array_keys($translations) as $locale) {
            if (! in_array($locale, $locales, true)) {
                throw ValidationException::withMessages(['translations' => __('commerce::products.locale_not_allowed')]);
            }
        }

        return DB::transaction(function () use ($product, $data, $translations, $locales): Product {
            $product ??= new Product;
            $product->fill(collect($data)->only(['type', 'status', 'delivery_handler', 'delivery_config'])->all());
            $product->save();

            foreach ($locales as $locale) {
                $translation = $translations[$locale] ?? [];
                if (! isset($translation['name']) || trim((string) $translation['name']) === '') {
                    if ($locale === ContentLocale::default()) {
                        throw ValidationException::withMessages(['translations.'.$locale.'.name' => __('commerce::products.default_name_required')]);
                    }

                    continue;
                }

                ProductTranslation::query()->updateOrCreate(
                    ['product_id' => $product->id, 'locale' => $locale],
                    collect($translation)->only(['name', 'description', 'seo_title', 'seo_description'])->all(),
                );
            }

            return $product->load('translations');
        });
    }
}
