<?php

declare(strict_types=1);

namespace Fayne\Commerce\Orders\Models;

use Fayne\CMS\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class PaymentConfirmation extends Model
{
    use BelongsToTenant;

    protected $table = 'commerce_payment_confirmations';

    protected $fillable = ['order_id', 'provider', 'provider_reference', 'amount', 'currency', 'outcome'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }
}
