<?php

namespace Saviogodinho2002\Drifguard\Tests\Console;

use Illuminate\Support\Facades\Artisan;
use Saviogodinho2002\Drifguard\Tests\TestCase;

class ContextListCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['drifguard.model_namespace' => 'Saviogodinho2002\\Drifguard\\Tests\\Fixtures\\Models']);
        config(['drifguard.models_path'     => $this->fixturesPath('Models')]);
        config(['drifguard.context_docs'    => ['base_path' => $this->fixturesPath(), 'map' => [], 'convention_path' => null]]);
    }

    public function test_lists_all_models_when_none_specified(): void
    {
        Artisan::call('drifguard:context:list', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $models = array_column($decoded, 'model');
        $this->assertContains('Post', $models);
        $this->assertContains('Author', $models);
    }

    public function test_restricts_to_specified_models(): void
    {
        Artisan::call('drifguard:context:list', ['--model' => ['Post'], '--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertCount(1, $decoded);
        $this->assertSame('Post', $decoded[0]['model']);
        $this->assertFalse($decoded[0]['found']); // nenhum context doc configurado nos fixtures
    }
}
