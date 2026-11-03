<?php

declare(strict_types=1);

namespace Fayne\Commerce\Console\Commands;

use Fayne\CMS\Models\Tenant;
use Fayne\CMS\Support\TenantContext;
use Fayne\Commerce\Events\CommerceOutboxEvent;
use Fayne\Commerce\Support\Outbox\OutboxMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Throwable;

final class PublishCommerceOutbox extends Command
{
    protected $signature = 'commerce:outbox:publish {--limit=100}';

    protected $description = 'Publish pending Commerce outbox events.';

    public function handle(TenantContext $tenantContext): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $published = 0;
        if (config('filament-cms.tenancy.enabled', false)) {
            foreach (Tenant::query()->where('is_active', true)->orderBy('id')->get() as $tenant) {
                $published += $tenantContext->run($tenant, fn (): int => $this->publishForTenant($limit - $published));
                if ($published >= $limit) {
                    break;
                }
            }
        } else {
            $published = $this->publishForTenant($limit);
        }
        $this->info("Published {$published} Commerce outbox event(s).");

        return self::SUCCESS;
    }

    private function publishForTenant(int $limit): int
    {
        $published = 0;
        for ($index = 0; $index < $limit; $index++) {
            $claim = DB::transaction(function (): ?array {
                $message = OutboxMessage::query()->whereNull('published_at')
                    ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
                    ->where(fn ($query) => $query->whereNull('locked_at')->orWhere('locked_at', '<=', now()->subMinutes(5)))
                    ->orderBy('id')->lockForUpdate()->skipLocked()->first();
                if (! $message) {
                    return null;
                }
                $token = (string) Str::uuid();
                $message->update(['locked_at' => now(), 'lock_token' => $token, 'attempts' => $message->attempts + 1]);

                return ['id' => $message->getKey(), 'tenant_id' => (int) $message->tenant_id, 'token' => $token, 'key' => $message->event_key, 'type' => $message->event_type, 'payload' => $message->payload];
            }, attempts: 3);
            if (! $claim) {
                break;
            }

            try {
                Event::dispatch(new CommerceOutboxEvent($claim['tenant_id'], $claim['key'], $claim['type'], $claim['payload']));
                OutboxMessage::query()->whereKey($claim['id'])->where('lock_token', $claim['token'])->update(['published_at' => now(), 'locked_at' => null, 'lock_token' => null, 'last_error' => null]);
                $published++;
            } catch (Throwable $exception) {
                OutboxMessage::query()->whereKey($claim['id'])->where('lock_token', $claim['token'])->update([
                    'locked_at' => null,
                    'lock_token' => null,
                    'available_at' => now()->addSeconds(min(3600, 2 ** min(10, (int) $index))),
                    'last_error' => mb_substr($exception->getMessage(), 0, 65000),
                ]);
            }
        }

        return $published;
    }
}
