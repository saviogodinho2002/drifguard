<?php

namespace Saviogodinho2002\DriftGuard\Tests\Console;

use Illuminate\Support\Facades\Artisan;
use Saviogodinho2002\DriftGuard\Contracts\AnalysisClient;
use Saviogodinho2002\DriftGuard\Tests\Fixtures\FakeAnalysisClient;
use Saviogodinho2002\DriftGuard\Tests\TestCase;

class AnalyzeModelsCommandTest extends TestCase
{
    private string $tmpOutputConfig;
    private string $tmpStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpOutputConfig = sys_get_temp_dir() . '/driftguard_cmd_models_' . uniqid() . '.php';
        $this->tmpStorage      = sys_get_temp_dir() . '/driftguard_cmd_storage_' . uniqid();

        config(['driftguard.model_namespace'   => 'Saviogodinho2002\\DriftGuard\\Tests\\Fixtures\\Models']);
        config(['driftguard.models_path'       => $this->fixturesPath('Models')]);
        config(['driftguard.supporting_paths'  => []]);
        config(['driftguard.output_path'       => $this->tmpOutputConfig]);
        config(['driftguard.storage_path'      => $this->tmpStorage]);
        config(['driftguard.fields'            => []]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->tmpStorage}/*") ?: []);
        @rmdir($this->tmpStorage);
        @unlink($this->tmpOutputConfig);
        parent::tearDown();
    }

    /** Regra B: --dry-run nunca chama o LLM, mesmo pedindo --force (reanalisar tudo). */
    public function test_dry_run_never_calls_the_analysis_client(): void
    {
        $fake = new FakeAnalysisClient();
        $this->app->bind(AnalysisClient::class, fn() => $fake);

        Artisan::call('driftguard:analyze', ['--dry-run' => true, '--force' => true]);

        $this->assertCount(0, $fake->mensagensRecebidas, '--dry-run não deve chamar o cliente de análise nem uma vez');
    }

    public function test_dry_run_json_output_is_valid_and_lists_models(): void
    {
        Artisan::call('driftguard:analyze', ['--dry-run' => true, '--force' => true, '--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertIsArray($decoded);
        $this->assertSame('force', $decoded['mode']);
        $this->assertContains('Post', array_column($decoded['models'], 'model'));
        $this->assertContains('Author', array_column($decoded['models'], 'model'));
    }

    public function test_json_output_on_real_run_is_valid_json_with_proposals(): void
    {
        $fake = (new FakeAnalysisClient())
            ->enqueueProposeUpdate(['descricao' => 'Autor de blog.', 'notas' => 'x'])
            ->enqueueProposeUpdate(['descricao' => 'Post de blog.', 'notas' => 'y']);
        $this->app->bind(AnalysisClient::class, fn() => $fake);

        Artisan::call('driftguard:analyze', ['--model' => ['Author', 'Post'], '--json' => true]);
        $saida   = Artisan::output();
        $decoded = json_decode($saida, true);

        $this->assertIsArray($decoded, 'saída deveria ser JSON válido, recebido: ' . $saida);
        $this->assertArrayHasKey('Author', $decoded['proposals']);
        $this->assertArrayHasKey('Post', $decoded['proposals']);
    }
}
