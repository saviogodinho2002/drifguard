<?php

namespace Saviogodinho2002\DriftGuard\Tests\Console;

use Illuminate\Support\Facades\Artisan;
use Saviogodinho2002\DriftGuard\Contracts\AnalysisClient;
use Saviogodinho2002\DriftGuard\ModelSyncService;
use Saviogodinho2002\DriftGuard\Tests\Fixtures\FakeAnalysisClient;
use Saviogodinho2002\DriftGuard\Tests\TestCase;

class AnswerCommandTest extends TestCase
{
    private string $tmpStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpStorage = sys_get_temp_dir() . '/driftguard_answer_storage_' . uniqid();

        config(['driftguard.model_namespace'  => 'Saviogodinho2002\\DriftGuard\\Tests\\Fixtures\\Models']);
        config(['driftguard.models_path'      => $this->fixturesPath('Models')]);
        config(['driftguard.supporting_paths' => []]);
        config(['driftguard.storage_path'     => $this->tmpStorage]);
        config(['driftguard.fields'           => []]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->tmpStorage}/*") ?: []);
        @rmdir($this->tmpStorage);
        parent::tearDown();
    }

    public function test_answers_pending_question_and_marks_model_for_rerun(): void
    {
        $fake = (new FakeAnalysisClient())->enqueueAskQuestion('Campo X é obrigatório em algum fluxo?');
        $this->app->bind(AnalysisClient::class, fn() => $fake);

        /** @var ModelSyncService $service */
        $service = $this->app->make(ModelSyncService::class);
        $service->runAnalysis(['Post'], fn() => null);
        $this->assertEmpty($service->modelsAwaitingRerun());

        $exitCode = Artisan::call('driftguard:answer', ['model' => 'Post', 'resposta' => ['Sim,', 'é', 'obrigatório.']]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(['Post'], $service->modelsAwaitingRerun());
        $this->assertStringContainsString('Campo X é obrigatório em algum fluxo?', Artisan::output());
    }

    public function test_returns_failure_when_no_pending_question_for_model(): void
    {
        $exitCode = Artisan::call('driftguard:answer', ['model' => 'Post', 'resposta' => ['qualquer', 'coisa']]);

        $this->assertSame(1, $exitCode);
    }

    public function test_rejects_empty_answer(): void
    {
        $exitCode = Artisan::call('driftguard:answer', ['model' => 'Post', 'resposta' => ['   ']]);

        $this->assertSame(1, $exitCode);
    }
}
