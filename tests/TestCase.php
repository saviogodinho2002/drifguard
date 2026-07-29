<?php

namespace Saviogodinho2002\Drifguard\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Saviogodinho2002\Drifguard\DrifguardServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [DrifguardServiceProvider::class];
    }

    protected function fixturesPath(string $path = ''): string
    {
        return __DIR__ . '/Fixtures' . ($path ? "/{$path}" : '');
    }
}
