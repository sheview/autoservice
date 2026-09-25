<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The app connects as a non-owner role that cannot create tables, so the test schema
     * is built once with the owner connection. RefreshDatabase then only wraps each test
     * in a transaction on the app connection.
     */
    protected function setUpTraits()
    {
        if (isset(class_uses_recursive(static::class)[RefreshDatabase::class]) && ! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh', ['--database' => 'pgsql_migrate', '--drop-types' => true]);
            $this->app[Kernel::class]->setArtisan(null);

            RefreshDatabaseState::$migrated = true;
        }

        return parent::setUpTraits();
    }
}
