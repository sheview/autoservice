<?php

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

it('requires BelongsToTenant on every module model whose table has tenant_id', function () {
    $checked = [];
    $offenders = [];

    foreach (glob(app_path('Modules/*/Models/*.php')) as $file) {
        $module = basename(dirname($file, 2));
        $class = "App\\Modules\\{$module}\\Models\\".basename($file, '.php');

        if (! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        if (! Schema::hasColumn((new $class)->getTable(), 'tenant_id')) {
            continue;
        }

        $checked[] = $class;
        if (! in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
            $offenders[] = $class;
        }
    }

    expect($checked)->toContain(Branch::class)
        ->and($offenders)->toBe([]);
});
