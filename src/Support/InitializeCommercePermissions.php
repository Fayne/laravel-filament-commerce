<?php

declare(strict_types=1);

namespace Fayne\Commerce\Support;

use Fayne\CMS\Support\Authorization\PermissionRegistry;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class InitializeCommercePermissions
{
    public function execute(): void
    {
        $registry = new PermissionRegistry;
        $registry->register('commerce', require __DIR__.'/../../resources/permissions.php');
        $guard = config('filament-cms.guard', 'admin');

        DB::transaction(function () use ($registry, $guard): void {
            $roles = Role::query()->where('guard_name', $guard)->where(function ($query): void {
                $query->where(function ($query): void {
                    $query->where('scope', 'tenant')->whereIn('name', ['tenant-manager', 'content-editor', 'tenant-viewer']);
                });
                if (! config('filament-cms.tenancy.enabled', false)) {
                    $query->orWhere(function ($query): void {
                        $query->where('scope', 'platform')->where('authorization_scope_id', 0)->whereIn('name', ['admin', 'editor']);
                    });
                }
            })->get();
            foreach ($roles as $role) {
                $profile = match ($role->name) {
                    'tenant-viewer' => 'viewer',
                    'content-editor', 'editor' => 'editor',
                    default => 'all',
                };
                $ids = Permission::query()->where('guard_name', $guard)->where('scope', 'tenant')
                    ->whereIn('name', $registry->tenantPermissions($profile))->pluck('id');
                $role->permissions()->syncWithoutDetaching($ids);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
