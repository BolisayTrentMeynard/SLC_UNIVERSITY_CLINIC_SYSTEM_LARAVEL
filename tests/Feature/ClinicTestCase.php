<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ClinicApi;
use Tests\TestCase;

/**
 * Base for the clinic tests. Builds the schema in the TEST database (migrate:fresh, once per run),
 * wraps every test in a transaction, and seeds nurse1 / doctor1.
 */
abstract class ClinicTestCase extends TestCase
{
    use ClinicApi, RefreshDatabase;

    protected function setUp(): void
    {
        // migrate:fresh DROPS every table, so never run against a real database.
        $name = (string) (getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? ''));
        if (! str_ends_with($name, '_test')) {
            throw new \RuntimeException("Refusing to run: DB_DATABASE is '{$name}'. Run the tests through phpunit.xml (php artisan test); the database name must end in _test.");
        }

        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }
}
