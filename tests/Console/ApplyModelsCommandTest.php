<?php

namespace Saviogodinho2002\DriftGuard\Tests\Console;

use Illuminate\Support\Facades\Artisan;
use Saviogodinho2002\DriftGuard\Contracts\AnalysisClient;
use Saviogodinho2002\DriftGuard\Tests\Fixtures\FakeAnalysisClient;
use Saviogodinho2002\DriftGuard\Tests\TestCase;

class ApplyModelsCommandTest extends TestCase
{
    private string $tmpOutputConfig;
    private string $tmpStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpOutputConfig = sys_get_temp_dir() . '/driftguard_apply_models_' . uniqid() . '.php';
        $this->tmpStorage      = sys_get_temp_dir() . '/driftguard_apply_storage_' . uniqid();

        config(['driftguard.model_namespace' => 'Saviogodinho2002\\DriftGuard\\Tests\\Fixtures\\Models']);
        config(['driftguard.models_path'     => $this->fixturesPath('Models')]);
        config(['driftguard.supporting_paths' => []]);
        config(['driftguard.output_path'     => $this->tmpOutputConfig]);
        config(['driftguard.storage_path'    => $this->tmpStorage]);
        config(['driftguard.fields'          => []]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->tmpStorage}/*") ?: []);
        @rmdir($this->tmpStorage);
        @unlink($this->tmpOutputConfig);
        parent::tearDown();
    }

    private function gerarProposta(): void
    {
        $fake = (new FakeAnalysisClient())->enqueueProposeUpdate(['descricao' => 'Post de blog.', 'notas' => 'x']);
        $this->app->bind(AnalysisClient::class, fn() => $fake);
        Artisan::call('driftguard:analyze', ['--model' => ['Post']]);
    }

    /** Regra B: --json sem --force não aplica silenciosamente — exige confirmação explícita. */
    public function test_json_without_force_does_not_apply_and_requires_force(): void
    {
        $this->gerarProposta();

        $exitCode = Artisan::call('driftguard:apply', ['--json' => true]);
        $decoded  = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('confirmation_required', $decoded['status']);
        $this->assertFileDoesNotExist($this->tmpOutputConfig);
    }

    public function test_json_dry_run_shows_diff_without_applying(): void
    {
        $this->gerarProposta();

        Artisan::call('driftguard:apply', ['--json' => true, '--dry-run' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame('dry_run', $decoded['status']);
        $this->assertNotEmpty($decoded['diff']);
        $this->assertFileDoesNotExist($this->tmpOutputConfig);
    }

    public function test_json_with_force_applies_and_returns_valid_json(): void
    {
        $this->gerarProposta();

        $exitCode = Artisan::call('driftguard:apply', ['--json' => true, '--force' => true]);
        $decoded  = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('applied', $decoded['status']);
        $this->assertFileExists($this->tmpOutputConfig);
    }
}
