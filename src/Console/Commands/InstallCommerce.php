<?php

declare(strict_types=1);

namespace Fayne\Commerce\Console\Commands;

use Fayne\CMS\Database\Seeders\InitializeRolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

final class InstallCommerce extends Command
{
    use ConfirmableTrait;

    protected $signature = 'ec:core:install {--force : Allow installation in production}';

    protected $description = 'Publish Commerce configuration, run migrations, and initialize permissions.';

    public function handle(): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $status = $this->call('vendor:publish', ['--tag' => 'commerce-config']);
        if ($status !== self::SUCCESS) {
            return $status;
        }

        $status = $this->call('migrate', ['--force' => true]);
        if ($status !== self::SUCCESS) {
            return $status;
        }

        return $this->call('db:seed', [
            '--class' => InitializeRolesAndPermissionsSeeder::class,
            '--force' => true,
        ]);
    }
}
