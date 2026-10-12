<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Safety net: RefreshDatabase runs `migrate:fresh`, which DROPS every table. If the real
     * (Docker) environment ever overrides phpunit.xml again, fail loudly here instead of
     * wiping the development MySQL database.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $config = $app['config'];
        $connection = $config->get('database.default');
        $database = $config->get("database.connections.{$connection}.database");

        if (! $app->environment('testing') || $connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(sprintf(
                'Refusing to run tests: env=%s, DB=%s (%s). Tests must use APP_ENV=testing with in-memory SQLite - check phpunit.xml.',
                $app->environment(), $connection, $database,
            ));
        }

        return $app;
    }
}
