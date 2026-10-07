<?php

use App\Providers\ModuleServiceProvider;
use Illuminate\Support\Facades\DB;

it('discovers every module folder in app/Modules', function () {
    $modules = array_map('basename', ModuleServiceProvider::modulePaths(app_path('Modules')));

    expect($modules)->toContain(
        'Tenancy', 'Identity', 'Asset', 'Contract', 'Service', 'Maintenance',
        'Inventory', 'Labeling', 'Document', 'Survey', 'Reporting', 'Platform',
    );
});

it('registers module migration folders with the migrator', function () {
    $paths = array_map(
        fn ($p) => str_replace('\\', '/', $p),
        app('migrator')->paths(),
    );

    expect($paths)->toContain(str_replace('\\', '/', app_path('Modules/Tenancy/database/migrations')));
});

it('runs on MariaDB 10.11 with explicit timestamp defaults', function () {
    expect(DB::connection()->getDriverName())->toBe('mariadb')
        ->and(DB::selectOne('select version() as v')->v)->toStartWith('10.11')
        // Otherwise the first NOT NULL timestamp of a table silently becomes "ON UPDATE now()".
        ->and((int) DB::selectOne('select @@explicit_defaults_for_timestamp as v')->v)->toBe(1)
        // Thai time on the connection whatever the server's clock (config database.connections.mariadb.timezone)
        ->and(DB::selectOne('select @@session.time_zone as v')->v)->toBe('+07:00');
});

it('uses Bangkok timezone and Thai locale', function () {
    expect(config('app.timezone'))->toBe('Asia/Bangkok')
        ->and(app()->getLocale())->toBe('th');
});
