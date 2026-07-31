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
            ->enqueueProposeUpdate(['descricao' => 'Autor de blog.', 'notas' => 'x'], usage: ['cost_usd' => 0.01, 'prompt_tokens' => 50, 'completion_tokens' => 10])
            ->enqueueProposeUpdate(['descricao' => 'Post de blog.', 'notas' => 'y'], usage: ['cost_usd' => 0.02, 'prompt_tokens' => 60, 'completion_tokens' => 20]);
        $this->app->bind(AnalysisClient::class, fn() => $fake);

        Artisan::call('driftguard:analyze', ['--model' => ['Author', 'Post'], '--json' => true]);
        $saida   = Artisan::output();
        $decoded = json_decode($saida, true);

        $this->assertIsArray($decoded, 'saída deveria ser JSON válido, recebido: ' . $saida);
        $this->assertArrayHasKey('Author', $decoded['proposals']);
        $this->assertArrayHasKey('Post', $decoded['proposals']);

        $this->assertArrayHasKey('usage', $decoded, 'gap real reportado em teste: --json não expunha custo/tokens');
        $this->assertEqualsWithDelta(0.03, $decoded['usage']['cost_usd'], 0.0001);
        $this->assertSame(110, $decoded['usage']['prompt_tokens']);
        $this->assertSame(30, $decoded['usage']['completion_tokens']);
    }

    /** Nenhum model a analisar não chama runAnalysis() — usage precisa manter a MESMA forma (nulls) pro JSON ser previsível. */
    public function test_json_output_when_no_models_to_analyze_still_includes_usage_key(): void
    {
        // bind defensivo: este caminho não deveria chamar o cliente de análise; se chamar por
        // engano, falha alto (exceção do fake) em vez de tentar uma requisição HTTP real.
        $fake = new FakeAnalysisClient();
        $this->app->bind(AnalysisClient::class, fn() => $fake);

        // catálogo já tem entrada pra TODOS os models descobertos (Author, Post) — sem --force e
        // sem --model, findMissingModels() fica vazio.
        file_put_contents($this->tmpOutputConfig, "<?php\nreturn ['Author' => ['descricao' => 'x'], 'Post' => ['descricao' => 'y']];\n");

        // context.json com last_commit_hash = HEAD atual: modelsChangedSinceLastRun() faz
        // `git diff {hash}..HEAD`, que fica vazio (nenhum commit novo desde a escrita deste
        // arquivo) — sem isso, o range default (HEAD~10..HEAD) pega qualquer mudança recente real
        // no repo em Author.php/Post.php e reinclui os models na lista, tornando o teste instável.
        if (!is_dir($this->tmpStorage)) {
            mkdir($this->tmpStorage, 0755, true);
        }
        $head = trim(shell_exec('git rev-parse HEAD') ?? '');
        file_put_contents("{$this->tmpStorage}/context.json", json_encode(['last_commit_hash' => $head]));

        Artisan::call('driftguard:analyze', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame([], $decoded['models'], 'pré-condição do teste: lista de models a analisar devia estar vazia');
        $this->assertArrayHasKey('usage', $decoded);
        $this->assertSame(['cost_usd' => null, 'prompt_tokens' => null, 'completion_tokens' => null], $decoded['usage']);
    }

    /** Caminho "nenhuma proposta retornada" (cliente não propôs nem perguntou) também expõe usage. */
    public function test_json_output_when_no_proposal_returned_still_includes_usage_key(): void
    {
        $fake = new FakeAnalysisClient(); // fila vazia — chat() cai no fallback sem tool_calls
        $this->app->bind(AnalysisClient::class, fn() => $fake);

        Artisan::call('driftguard:analyze', ['--model' => ['Post'], '--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertArrayHasKey('usage', $decoded);
        $this->assertSame(['cost_usd' => null, 'prompt_tokens' => null, 'completion_tokens' => null], $decoded['usage']);
    }
}
