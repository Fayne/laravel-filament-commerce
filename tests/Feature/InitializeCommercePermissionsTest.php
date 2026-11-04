<?php

use Fayne\Commerce\Support\InitializeCommercePermissions;
use Fayne\Commerce\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

uses(TestCase::class);

test('installation adds commerce grants to existing built-in roles and preserves custom roles', function (): void {
    config(['permission' => require dirname((new ReflectionClass(PermissionRegistrar::class))->getFileName(), 2).'/config/permission.php']);
    config(['filament-cms.tenancy.enabled' => true,
        'permission.table_names.roles' => 'roles', 'permission.table_names.permissions' => 'permissions',
        'permission.table_names.role_has_permissions' => 'role_has_permissions',
        'permission.column_names.role_pivot_key' => 'role_id',
        'permission.column_names.permission_pivot_key' => 'permission_id',
    ]);
    Schema::create('roles', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->string('scope');
        $table->unsignedBigInteger('authorization_scope_id');
    });
    Schema::create('permissions', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->string('scope');
    });
    Schema::create('role_has_permissions', function (Blueprint $table): void {
        $table->unsignedBigInteger('role_id');
        $table->unsignedBigInteger('permission_id');
        $table->primary(['role_id', 'permission_id']);
    });
    foreach (['tenant-manager', 'tenant-viewer', 'custom'] as $name) {
        DB::table('roles')->insert(['name' => $name, 'guard_name' => 'admin', 'scope' => 'tenant', 'authorization_scope_id' => 1]);
    }
    foreach (['tenant.viewAnyOrder', 'tenant.shipOrder', 'tenant.unrelated'] as $name) {
        DB::table('permissions')->insert(['name' => $name, 'guard_name' => 'admin', 'scope' => 'tenant']);
    }
    DB::table('role_has_permissions')->insert(['role_id' => 1, 'permission_id' => 3]);
    app(InitializeCommercePermissions::class)->execute();
    app(InitializeCommercePermissions::class)->execute();

    expect(DB::table('role_has_permissions')->where('role_id', 1)->pluck('permission_id')->sort()->values()->all())->toBe([1, 2, 3])
        ->and(DB::table('role_has_permissions')->where('role_id', 2)->pluck('permission_id')->all())->toBe([1])
        ->and(DB::table('role_has_permissions')->where('role_id', 3)->count())->toBe(0);
});
