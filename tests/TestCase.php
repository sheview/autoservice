<?php

namespace Tests;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Tenant every database test starts in (like a request of a logged-in user). */
    protected ?Tenant $tenant = null;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->usesDatabase()) {
            $this->tenant = Tenant::create(['name' => 'Default', 'slug' => 'default', 'subdomain' => 'default']);
            app(TenantContext::class)->set($this->tenant);
        }
    }

    /**
     * A request switches the tenant (ResolveTenant). Put the test code back in the tenant
     * it was in, so assertions after a request read the same tenant as before.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $context = app(TenantContext::class);
        $previous = $context->id();

        try {
            return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        } finally {
            $context->set($previous);
        }
    }

    /**
     * The app connects as a non-owner role that cannot create tables, so the test schema
     * is built once with the owner connection. RefreshDatabase then only wraps each test
     * in a transaction on the app connection.
     */
    protected function setUpTraits()
    {
        if ($this->usesDatabase() && ! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh', ['--database' => 'pgsql_migrate', '--drop-types' => true]);
            $this->app[Kernel::class]->setArtisan(null);

            RefreshDatabaseState::$migrated = true;
        }

        return parent::setUpTraits();
    }

    private function usesDatabase(): bool
    {
        return isset(class_uses_recursive(static::class)[RefreshDatabase::class]);
    }
}
