<?php

declare(strict_types=1);

namespace Fayne\Commerce\Catalog\Models;

use Fayne\CMS\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProductTranslation extends Model
{
    use BelongsToTenant;

    protected $table = 'commerce_product_translations';

    protected $fillable = ['product_id', 'locale', 'name', 'description', 'seo_title', 'seo_description'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
