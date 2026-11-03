<?php

declare(strict_types=1);

namespace Fayne\Commerce\Tenancy\Models;

use Fayne\CMS\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class TenantCommerceSetting extends Model
{
    use BelongsToTenant;

    protected $table = 'commerce_tenant_settings';

    protected $fillable = ['currency'];
}
