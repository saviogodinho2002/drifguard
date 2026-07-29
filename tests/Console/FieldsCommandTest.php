<?php

namespace Saviogodinho2002\Drifguard\Tests\Console;

use Illuminate\Support\Facades\Artisan;
use Saviogodinho2002\Drifguard\Tests\TestCase;

class FieldsCommandTest extends TestCase
{
    public function test_lists_configured_fields_as_json(): void
    {
        config(['drifguard.fields' => [
            ['name' => 'gatilhos', 'type' => 'string', 'llm_instructions' => 'termos de busca'],
        ]]);

        Artisan::call('drifguard:fields', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertCount(1, $decoded);
        $this->assertSame('gatilhos', $decoded[0]['name']);
        $this->assertSame('string', $decoded[0]['type']);
    }

    public function test_empty_fields_returns_success_not_error(): void
    {
        config(['drifguard.fields' => []]);

        $exitCode = Artisan::call('drifguard:fields');

        $this->assertSame(0, $exitCode);
    }
}
