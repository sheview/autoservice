<?php

namespace App\Modules\Platform\Console;

use Database\Seeders\SampleCompanySeeder;
use Illuminate\Console\Command;

/**
 * Everything to run once new code is in place (after composer install and npm run build), in
 * order, stopping at the first step that fails: the old caches cleared, migrations, permissions
 * (platform:sync-permissions), the training company (SampleCompanySeeder, skipped when it is
 * there), and in production the caches built again. Production goes into maintenance mode
 * meanwhile and always comes back out, even after a failed step.
 *
 * On Plesk: Git → "Additional deployment actions" → php artisan platform:deploy
 */
class DeployCommand extends Command
{
    protected $signature = 'platform:deploy {--without-sample : Do not make the training company}';

    protected $description = 'Migrate, sync permissions, make the training company and cache, after a deploy';

    public function handle(): int
    {
        $production = $this->laravel->isProduction();

        if ($production) {
            $this->call('down', ['--retry' => 15]);
        }

        try {
            foreach ($this->steps($production) as [$command, $arguments]) {
                $this->components->info($command);
                if ($this->call($command, $arguments) !== self::SUCCESS) {
                    $this->components->error("{$command} failed: deploy stopped.");

                    return self::FAILURE;
                }
            }
        } finally {
            if ($production) {
                $this->call('up');
            }
        }

        $this->components->info('Deploy finished.');

        return self::SUCCESS;
    }

    /**
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function steps(bool $production): array
    {
        return array_values(array_filter([
            // Caches of the old code (or an old .env) must not steer the steps below.
            ['config:clear', []],
            ['route:clear', []],
            ['view:clear', []],
            ['migrate', ['--force' => true]],
            ['platform:sync-permissions', []],
            $this->option('without-sample') ? null : ['db:seed', ['--class' => SampleCompanySeeder::class, '--force' => true]],
            // Off production the caches would hide changes to config and routes.
            $production ? ['optimize', []] : null,
        ]));
    }
}
