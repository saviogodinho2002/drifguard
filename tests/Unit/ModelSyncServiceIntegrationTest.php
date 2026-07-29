<?php

namespace Saviogodinho2002\Drifguard\Tests\Unit;

use Saviogodinho2002\Drifguard\ModelSyncService;
use Saviogodinho2002\Drifguard\Support\ConfigWriter;
use Saviogodinho2002\Drifguard\Support\ContextDocsResolver;
use Saviogodinho2002\Drifguard\Support\FieldSpec;
use Saviogodinho2002\Drifguard\Support\ModelDiscovery;
use Saviogodinho2002\Drifguard\Support\ModelReflector;
use Saviogodinho2002\Drifguard\Tests\Fixtures\FakeAnalysisClient;
use Saviogodinho2002\Drifguard\Tests\TestCase;

/**
 * Fim-a-fim contra um domínio de fixture genérico (blog: Author/Post) — prova que o pacote produz
 * o fluxo completo (análise -> proposta -> diff -> aplicação) sem depender de nada específico de
 * um projeto real.
 */
class ModelSyncServiceIntegrationTest extends TestCase
{
    private string $tmpStorage;
    private string $tmpOutputConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpStorage      = sys_get_temp_dir() . '/drifguard_storage_' . uniqid();
        $this->tmpOutputConfig = sys_get_temp_dir() . '/drifguard_models_' . uniqid() . '.php';
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->tmpStorage}/*") ?: []);
        @rmdir($this->tmpStorage);
        @unlink($this->tmpOutputConfig);
        parent::tearDown();
    }

    private function makeService(FakeAnalysisClient $client, array $fieldSpecs = []): ModelSyncService
    {
        return new ModelSyncService(
            client: $client,
            discovery: new ModelDiscovery(
                modelsPath: __DIR__ . '/../Fixtures/Models',
                modelNamespace: 'Saviogodinho2002\\Drifguard\\Tests\\Fixtures\\Models',
                supportingPaths: [],
            ),
            reflector: new ModelReflector(modelNamespace: 'Saviogodinho2002\\Drifguard\\Tests\\Fixtures\\Models'),
            contextDocs: new ContextDocsResolver(basePath: __DIR__),
            configWriter: new ConfigWriter(),
            scopeClassWriter: null,
            fieldSpecs: $fieldSpecs,
            extraPromptRules: null,
            outputConfigPath: $this->tmpOutputConfig,
            storagePath: $this->tmpStorage,
        );
    }

    public function test_full_analyze_and_apply_cycle_on_generic_domain(): void
    {
        $client = (new FakeAnalysisClient())->enqueueProposeUpdate([
            'descricao' => 'Uma publicação de blog escrita por um autor.',
            'notas'     => 'published_at nulo significa rascunho ainda não publicado.',
        ]);

        $service = $this->makeService($client, [
            new FieldSpec(name: 'gatilhos', type: FieldSpec::TYPE_STRING, llmInstructions: 'termos de busca'),
        ]);

        $result = $service->runAnalysis(['Post'], fn() => null);

        $this->assertArrayHasKey('Post', $result['proposals']);
        $this->assertSame('Uma publicação de blog escrita por um autor.', $result['proposals']['Post']['descricao']);
        // fatos estruturais vieram de reflection, não da IA
        $this->assertSame('posts', $result['proposals']['Post']['tabela']);

        $current = $service->readOutputConfig();
        $diff    = $service->buildDiff($current, $result['proposals']);
        $this->assertNotEmpty($diff);

        $aplicado = $service->apply($current, $result['proposals']);
        $relido   = include $this->tmpOutputConfig;

        $this->assertSame('Uma publicação de blog escrita por um autor.', $relido['Post']['descricao']);

        // Nenhuma menção a domínio de projeto nenhum em lugar nenhum do resultado
        $comoTexto = json_encode($relido);
        foreach (['AcmeCorp', 'LegacyModelName', 'LegacyEntityName', 'internalProjectSlug'] as $termo) {
            $this->assertStringNotContainsString($termo, $comoTexto);
        }
    }

    public function test_ask_question_path_produces_pending_question_not_proposal(): void
    {
        $client  = (new FakeAnalysisClient())->enqueueAskQuestion('Este campo é obrigatório em algum fluxo de negócio específico?');
        $service = $this->makeService($client);

        $result = $service->runAnalysis(['Post'], fn() => null);

        $this->assertEmpty($result['proposals']);
        $this->assertCount(1, $result['questions']);
        $this->assertSame('Post', $result['questions'][0]['model']);
    }
}
