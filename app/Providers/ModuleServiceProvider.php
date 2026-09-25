<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Auto-registers every module in app/Modules/{Module}:
 *   - {Module}ServiceProvider (if the module has one)
 *   - routes/web.php          (loaded with the "web" middleware group)
 *   - database/migrations/    (added to the migrator paths)
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach (static::modulePaths(app_path('Modules')) as $path) {
            $module = basename($path);

            if (is_file("{$path}/{$module}ServiceProvider.php")) {
                $this->app->register("App\\Modules\\{$module}\\{$module}ServiceProvider");
            }
        }
    }

    public function boot(): void
    {
        foreach (static::modulePaths(app_path('Modules')) as $path) {
            $migrations = $path.'/database/migrations';
            if (is_dir($migrations)) {
                $this->loadMigrationsFrom($migrations);
            }

            $routes = $path.'/routes/web.php';
            if (is_file($routes) && ! $this->app->routesAreCached()) {
                Route::middleware('web')->group($routes);
            }
        }
    }

    /**
     * @return list<string>
     */
    public static function modulePaths(string $base): array
    {
        if (! is_dir($base)) {
            return [];
        }

        $paths = glob($base.'/*', GLOB_ONLYDIR) ?: [];
        sort($paths);

        return array_values($paths);
    }
}
