<?php

namespace Saviogodinho2002\Drifguard\Tests\Console;

use Illuminate\Support\Facades\Artisan;
use Saviogodinho2002\Drifguard\Contracts\AnalysisClient;
use Saviogodinho2002\Drifguard\Tests\Fixtures\FakeAnalysisClient;
use Saviogodinho2002\Drifguard\Tests\TestCase;

class InitCommandTest extends TestCase
{
    private string $tmpStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpStorage = sys_get_temp_dir() . '/drifguard_init_storage_' . uniqid();

        config(['drifguard.model_namespace'  => 'Saviogodinho2002\\Drifguard\\Tests\\Fixtures\\Models']);
        config(['drifguard.models_path'      => $this->fixturesPath('Models')]);
        config(['drifguard.supporting_paths' => []]);
        config(['drifguard.storage_path'     => $this->tmpStorage]);
        config(['drifguard.fields'           => []]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->tmpStorage}/*") ?: []);
        @rmdir($this->tmpStorage);
        parent::tearDown();
    }

    public function test_creates_context_json_with_current_commit_hash_never_calling_the_llm(): void
    {
        $fake = new FakeAnalysisClient();
        $this->app->bind(AnalysisClient::class, fn() => $fake);

        $exitCode = Artisan::call('drifguard:init');

        $this->assertSame(0, $exitCode);
        $this->assertCount(0, $fake->mensagensRecebidas, 'drifguard:init nunca deve chamar o LLM');

        $path = "{$this->tmpStorage}/context.json";
        $this->assertFileExists($path);

        $ctx = json_decode(file_get_contents($path), true);
        $this->assertArrayHasKey('last_commit_hash', $ctx);
        $this->assertNotEmpty($ctx['last_commit_hash']);
        $this->assertNull($ctx['last_analyzed_at']);
        $this->assertSame([], $ctx['pending_questions']);
    }

    public function test_does_not_overwrite_existing_context_without_force(): void
    {
        Artisan::call('drifguard:init');
        $path = "{$this->tmpStorage}/context.json";
        $original = file_get_contents($path);

        // simula estado acumulado (pergunta pendente) que --force sem querer apagaria
        file_put_contents($path, json_encode(['last_commit_hash' => 'abc', 'pending_questions' => [['model' => 'Post']]]));

        $exitCode = Artisan::call('drifguard:init');

        $this->assertSame(1, $exitCode);
        $ctx = json_decode(file_get_contents($path), true);
        $this->assertSame('abc', $ctx['last_commit_hash'], 'não deveria ter sobrescrito sem --force');
    }

    public function test_force_overwrites_existing_context(): void
    {
        Artisan::call('drifguard:init');
        $path = "{$this->tmpStorage}/context.json";
        file_put_contents($path, json_encode(['last_commit_hash' => 'abc', 'pending_questions' => []]));

        $exitCode = Artisan::call('drifguard:init', ['--force' => true]);

        $this->assertSame(0, $exitCode);
        $ctx = json_decode(file_get_contents($path), true);
        $this->assertNotSame('abc', $ctx['last_commit_hash']);
    }

    public function test_json_output_is_valid(): void
    {
        Artisan::call('drifguard:init', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame('initialized', $decoded['status']);
        $this->assertArrayHasKey('last_commit_hash', $decoded);
    }
}
