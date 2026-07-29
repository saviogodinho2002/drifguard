<?php

namespace Saviogodinho2002\DriftGuard\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Saviogodinho2002\DriftGuard\DriftGuardServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [DriftGuardServiceProvider::class];
    }

    protected function fixturesPath(string $path = ''): string
    {
        return __DIR__ . '/Fixtures' . ($path ? "/{$path}" : '');
    }
}
