<?php

declare(strict_types=1);

namespace Fayne\Commerce\Support\Outbox;

use Fayne\CMS\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class OutboxMessage extends Model
{
    use BelongsToTenant;

    protected $table = 'commerce_outbox_messages';

    protected $fillable = ['event_key', 'event_type', 'payload', 'available_at', 'published_at', 'locked_at', 'lock_token', 'attempts', 'last_error'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'available_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime', 'locked_at' => 'immutable_datetime', 'attempts' => 'integer'];
    }
}
