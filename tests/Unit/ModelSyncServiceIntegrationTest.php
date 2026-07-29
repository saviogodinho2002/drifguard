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

    private function makeService(
        FakeAnalysisClient $client,
        array $fieldSpecs = [],
        array $supportingPaths = [],
        ?string $allowedBasePath = null,
        int $maxSnippetChars = 6000,
    ): ModelSyncService {
        return new ModelSyncService(
            client: $client,
            discovery: new ModelDiscovery(
                modelsPath: __DIR__ . '/../Fixtures/Models',
                modelNamespace: 'Saviogodinho2002\\Drifguard\\Tests\\Fixtures\\Models',
                supportingPaths: $supportingPaths,
            ),
            reflector: new ModelReflector(modelNamespace: 'Saviogodinho2002\\Drifguard\\Tests\\Fixtures\\Models'),
            contextDocs: new ContextDocsResolver(basePath: __DIR__),
            configWriter: new ConfigWriter(),
            scopeClassWriter: null,
            fieldSpecs: $fieldSpecs,
            extraPromptRules: null,
            outputConfigPath: $this->tmpOutputConfig,
            storagePath: $this->tmpStorage,
            maxSnippetChars: $maxSnippetChars,
            allowedBasePath: $allowedBasePath,
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

    /**
     * Item C: fecha o gap de paridade com o `rerun` mode original — responder uma pergunta pendente
     * via `answerQuestion()` (o que `drifguard:answer` chama) precisa: (1) marcar o model pra
     * reanálise em `resolveRunState()`, e (2) alimentar o par pergunta/resposta na próxima análise.
     */
    public function test_answered_question_triggers_rerun_mode_and_feeds_context_into_next_analysis(): void
    {
        $client  = (new FakeAnalysisClient())->enqueueAskQuestion('published_at nulo é sempre rascunho?');
        $service = $this->makeService($client);

        $service->runAnalysis(['Post'], fn() => null);
        $this->assertEmpty($service->modelsAwaitingRerun(), 'antes de responder, não deve estar aguardando rerun');

        $resposta = $service->answerQuestion('Post', 'Sim, published_at nulo = rascunho.');
        $this->assertTrue($resposta['found']);
        $this->assertSame(['Post'], $service->modelsAwaitingRerun());

        $state = $service->resolveRunState(force: false, modelsOption: []);
        $this->assertSame('rerun', $state['mode']);
        $this->assertContains('Post', $state['models']);

        $client->enqueueProposeUpdate(['descricao' => 'Post do blog.', 'notas' => 'x']);
        $service->runAnalysis(['Post'], fn() => null);

        $ultimaChamada    = end($client->mensagensRecebidas);
        $conteudoUsuario  = $ultimaChamada[1]['content'];
        $this->assertStringContainsString('published_at nulo é sempre rascunho?', $conteudoUsuario);
        $this->assertStringContainsString('Sim, published_at nulo = rascunho.', $conteudoUsuario);

        // consumida — não deve mais entrar na fila de rerun depois de reanalisada
        $this->assertEmpty($service->modelsAwaitingRerun());
    }

    /** Item F: path fora do allowedBasePath é recusado explicitamente — nunca lido às cegas. */
    public function test_request_file_outside_allowed_base_path_is_refused_not_read(): void
    {
        $tmpSecreto = tempnam(sys_get_temp_dir(), 'drifguard_secreto_');
        file_put_contents($tmpSecreto, 'SEGREDO_NAO_DEVE_VAZAR');

        $client = (new FakeAnalysisClient())
            ->enqueueRequestFile($tmpSecreto)
            ->enqueueProposeUpdate(['descricao' => 'x', 'notas' => 'y']);

        $service = $this->makeService($client, allowedBasePath: __DIR__ . '/../Fixtures');

        $service->runAnalysis(['Post'], fn() => null);

        $conteudoTool = null;
        foreach ($client->mensagensRecebidas[1] ?? [] as $m) {
            if (($m['role'] ?? null) === 'tool') {
                $conteudoTool = $m['content'];
            }
        }

        $this->assertNotNull($conteudoTool);
        $this->assertStringContainsString('Acesso negado', $conteudoTool);
        $this->assertStringNotContainsString('SEGREDO_NAO_DEVE_VAZAR', $conteudoTool);

        @unlink($tmpSecreto);
    }

    /** Item F (caminho feliz): dentro do allowedBasePath, o conteúdo real é lido normalmente. */
    public function test_request_file_inside_allowed_base_path_is_read_normally(): void
    {
        $arquivo = __DIR__ . '/../Fixtures/Models/Post.php';

        $client = (new FakeAnalysisClient())
            ->enqueueRequestFile($arquivo)
            ->enqueueProposeUpdate(['descricao' => 'x', 'notas' => 'y']);

        $service = $this->makeService($client, allowedBasePath: __DIR__ . '/../Fixtures');

        $service->runAnalysis(['Post'], fn() => null);

        $conteudoTool = null;
        foreach ($client->mensagensRecebidas[1] ?? [] as $m) {
            if (($m['role'] ?? null) === 'tool') {
                $conteudoTool = $m['content'];
            }
        }

        $this->assertStringContainsString('class Post extends Model', $conteudoTool);
    }
}
