<?php

namespace Saviogodinho2002\DriftGuard\Tests\Unit;

use Saviogodinho2002\DriftGuard\ModelSyncService;
use Saviogodinho2002\DriftGuard\Support\ConfigWriter;
use Saviogodinho2002\DriftGuard\Support\ContextDocsResolver;
use Saviogodinho2002\DriftGuard\Support\FieldSpec;
use Saviogodinho2002\DriftGuard\Support\ModelDiscovery;
use Saviogodinho2002\DriftGuard\Support\ModelReflector;
use Saviogodinho2002\DriftGuard\Support\ScopeClassWriter;
use Saviogodinho2002\DriftGuard\Tests\Fixtures\FakeAnalysisClient;
use Saviogodinho2002\DriftGuard\Tests\TestCase;

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
        $this->tmpStorage      = sys_get_temp_dir() . '/driftguard_storage_' . uniqid();
        $this->tmpOutputConfig = sys_get_temp_dir() . '/driftguard_models_' . uniqid() . '.php';
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->tmpStorage}/index/*") ?: []);
        @rmdir("{$this->tmpStorage}/index");
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
        ?ScopeClassWriter $scopeClassWriter = null,
        int $maxTotalSnippetChars = 60000,
        int $maxSupportingFiles = 5,
    ): ModelSyncService {
        return new ModelSyncService(
            client: $client,
            discovery: new ModelDiscovery(
                modelsPath: __DIR__ . '/../Fixtures/Models',
                modelNamespace: 'Saviogodinho2002\\DriftGuard\\Tests\\Fixtures\\Models',
                supportingPaths: $supportingPaths,
            ),
            reflector: new ModelReflector(modelNamespace: 'Saviogodinho2002\\DriftGuard\\Tests\\Fixtures\\Models'),
            contextDocs: new ContextDocsResolver(basePath: __DIR__),
            configWriter: new ConfigWriter(),
            scopeClassWriter: $scopeClassWriter,
            fieldSpecs: $fieldSpecs,
            extraPromptRules: null,
            outputConfigPath: $this->tmpOutputConfig,
            storagePath: $this->tmpStorage,
            maxSnippetChars: $maxSnippetChars,
            allowedBasePath: $allowedBasePath,
            maxTotalSnippetChars: $maxTotalSnippetChars,
            maxSupportingFiles: $maxSupportingFiles,
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
     * via `answerQuestion()` (o que `driftguard:answer` chama) precisa: (1) marcar o model pra
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
        $tmpSecreto = tempnam(sys_get_temp_dir(), 'driftguard_secreto_');
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

    /** `request_method`: pede 1 método específico, recebe só o corpo dele — não o arquivo inteiro. */
    public function test_request_method_returns_only_the_requested_method_body(): void
    {
        $modelPath = __DIR__ . '/../Fixtures/Models/Post.php';

        $client = (new FakeAnalysisClient())
            ->enqueueRequestMethod([['path' => $modelPath, 'method' => 'author']])
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
        $this->assertStringContainsString('belongsTo(Author::class)', $conteudoTool);
        $this->assertStringNotContainsString('class Post extends Model', $conteudoTool, 'só o corpo do método deve vir, não a declaração da classe/arquivo inteiro');
    }

    /** `request_method`: pede métodos de arquivos DIFERENTES numa única chamada (lote). */
    public function test_request_method_supports_batching_multiple_files_in_one_call(): void
    {
        // dentro de Fixtures (não sys_get_temp_dir()) — precisa estar sob o MESMO allowedBasePath
        // que o arquivo do model (Fixtures/Models/Post.php), senão a guarda de path recusaria um
        // dos dois lados do lote.
        $dir = __DIR__ . '/../Fixtures/tmp_request_method_batch_' . uniqid();
        mkdir($dir, 0755, true);
        file_put_contents("{$dir}/PostController.php", <<<'PHP'
        <?php
        class PostController
        {
            public function index()
            {
                return Post::all();
            }

            public function show($id)
            {
                return Post::find($id);
            }
        }
        PHP);

        $modelPath = __DIR__ . '/../Fixtures/Models/Post.php';

        $client = (new FakeAnalysisClient())
            ->enqueueRequestMethod([
                ['path' => $modelPath, 'method' => 'author'],
                ['path' => "{$dir}/PostController.php", 'method' => 'show'],
            ])
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
        $this->assertStringContainsString('belongsTo(Author::class)', $conteudoTool, 'método do model deveria vir numa chamada só');
        $this->assertStringContainsString('Post::find($id)', $conteudoTool, 'método do arquivo de apoio deveria vir na MESMA chamada');
        $this->assertStringNotContainsString('Post::all()', $conteudoTool, 'método NÃO pedido (index) não deveria vir');

        array_map('unlink', glob("{$dir}/*.php") ?: []);
        rmdir($dir);
    }

    /** `request_method`: método pedido que não existe no arquivo nunca falha silenciosamente. */
    public function test_request_method_reports_missing_method_explicitly(): void
    {
        $modelPath = __DIR__ . '/../Fixtures/Models/Post.php';

        $client = (new FakeAnalysisClient())
            ->enqueueRequestMethod([['path' => $modelPath, 'method' => 'metodoQueNaoExiste']])
            ->enqueueProposeUpdate(['descricao' => 'x', 'notas' => 'y']);

        $service = $this->makeService($client, allowedBasePath: __DIR__ . '/../Fixtures');

        $service->runAnalysis(['Post'], fn() => null);

        $conteudoTool = null;
        foreach ($client->mensagensRecebidas[1] ?? [] as $m) {
            if (($m['role'] ?? null) === 'tool') {
                $conteudoTool = $m['content'];
            }
        }

        $this->assertStringContainsString('não encontrado', $conteudoTool);
        $this->assertStringContainsString('metodoQueNaoExiste', $conteudoTool);
    }

    /** `request_method` respeita a mesma guarda de `allowed_base_path` que `request_file` já tem. */
    public function test_request_method_outside_allowed_base_path_is_refused_not_read(): void
    {
        $tmpSecreto = tempnam(sys_get_temp_dir(), 'driftguard_secreto_');
        file_put_contents($tmpSecreto, "<?php\nclass X { public function segredo() { return 'SEGREDO_NAO_DEVE_VAZAR'; } }");

        $client = (new FakeAnalysisClient())
            ->enqueueRequestMethod([['path' => $tmpSecreto, 'method' => 'segredo']])
            ->enqueueProposeUpdate(['descricao' => 'x', 'notas' => 'y']);

        $service = $this->makeService($client, allowedBasePath: __DIR__ . '/../Fixtures');

        $service->runAnalysis(['Post'], fn() => null);

        $conteudoTool = null;
        foreach ($client->mensagensRecebidas[1] ?? [] as $m) {
            if (($m['role'] ?? null) === 'tool') {
                $conteudoTool = $m['content'];
            }
        }

        $this->assertStringContainsString('Acesso negado', $conteudoTool);
        $this->assertStringNotContainsString('SEGREDO_NAO_DEVE_VAZAR', $conteudoTool);

        @unlink($tmpSecreto);
    }

    /** `--full` (analiseCompleta): manda o arquivo do model INTEIRO, sem extração nem truncamento. */
    public function test_full_analysis_mode_sends_entire_model_file_without_extraction(): void
    {
        $client = (new FakeAnalysisClient())->enqueueProposeUpdate(['descricao' => 'x', 'notas' => 'y']);

        // maxSnippetChars minúsculo forçaria extração/truncamento normalmente
        $service = $this->makeService($client, maxSnippetChars: 5);

        $service->runAnalysis(['Post'], fn() => null, analiseCompleta: true);

        $conteudoUsuario = $client->mensagensRecebidas[0][1]['content'];

        $this->assertStringContainsString('class Post extends Model', $conteudoUsuario, 'arquivo inteiro deveria ir, mesmo maior que maxSnippetChars');
        $this->assertStringNotContainsString('descartado', $conteudoUsuario);
        $this->assertStringNotContainsString('truncado', $conteudoUsuario);

        // --full não deveria gravar/depender do ModelIndex (extração inteira é pulada)
        $this->assertFileDoesNotExist("{$this->tmpStorage}/index/Post.json");
    }

    /** `--full` ignora o orçamento total combinado — arquivo de apoio não é descartado. */
    public function test_full_analysis_mode_ignores_combined_budget(): void
    {
        $dir = sys_get_temp_dir() . '/driftguard_full_budget_test_' . uniqid();
        mkdir($dir, 0755, true);
        file_put_contents("{$dir}/PostController.php", "<?php\nclass PostController {\n    public function x() {\n        return Post::all();\n    }\n}");

        $client = (new FakeAnalysisClient())->enqueueProposeUpdate(['descricao' => 'x', 'notas' => 'y']);

        $service = $this->makeService(
            $client,
            supportingPaths: [$dir],
            maxTotalSnippetChars: 1, // orçamento absurdo — sem --full descartaria o arquivo de apoio
        );

        $service->runAnalysis(['Post'], fn() => null, analiseCompleta: true);

        $conteudoUsuario = $client->mensagensRecebidas[0][1]['content'];

        $this->assertStringContainsString('PostController', $conteudoUsuario, '--full deveria ignorar o orçamento total combinado');

        array_map('unlink', glob("{$dir}/*.php") ?: []);
        rmdir($dir);
    }

    /**
     * Achado do relatório real de produção: campo scope_class mal formado (nomes de variável
     * errados) recebe 1 chance de correção, com o erro específico + o contrato de formato
     * devolvidos como mensagem `tool` — a IA corrige na 2ª chamada, sem precisar de uma rodada de
     * análise inteira nova.
     */
    public function test_scope_class_field_gets_one_correction_retry_then_accepts(): void
    {
        $scopeDir = sys_get_temp_dir() . '/driftguard_scope_test_' . uniqid();
        mkdir($scopeDir, 0755, true);

        $corpoRuim = '$builder->whereRaw(\'1 = 0\'); return $builder;';
        $corpoBom  = 'return $query->where(\'author_id\', $context->id);';

        $client = (new FakeAnalysisClient())
            ->enqueueProposeUpdate(['descricao' => 'x', 'notas' => 'y', 'escopo_tenant' => $corpoRuim])
            ->enqueueProposeUpdate(['descricao' => 'x', 'notas' => 'y', 'escopo_tenant' => $corpoBom]);

        $service = $this->makeService(
            $client,
            fieldSpecs: [FieldSpec::scopeClass('escopo_tenant')->instructions('restrinja ao autor')],
            scopeClassWriter: new ScopeClassWriter(outputPath: $scopeDir, namespace: 'App\\DriftGuard\\Scopes'),
        );

        $result = $service->runAnalysis(['Post'], fn() => null);

        $this->assertCount(2, $client->mensagensRecebidas, 'esperava 1ª tentativa + 1 correção');
        $this->assertSame($corpoBom, $result['proposals']['Post']['escopo_tenant']);

        // a mensagem de correção explica o erro específico e reforça o contrato
        $mensagemDeCorrecao = $client->mensagensRecebidas[1];
        $ultimaTool         = array_values(array_filter($mensagemDeCorrecao, fn($m) => ($m['role'] ?? null) === 'tool'));
        $this->assertNotEmpty($ultimaTool);
        $this->assertStringContainsString('query', end($ultimaTool)['content']);

        array_map('unlink', glob("{$scopeDir}/*.php") ?: []);
        @rmdir($scopeDir);
    }

    /**
     * Se a IA insistir no erro mesmo depois da correção, o loop não trava tentando de novo — só 1
     * correção é permitida, e a proposta (ainda inválida) é devolvida como está. `apply()` continua
     * sendo o backstop final que de fato rejeita, sem gravar nada de errado.
     */
    public function test_scope_class_field_still_invalid_after_retry_returns_proposal_without_looping_forever(): void
    {
        $scopeDir = sys_get_temp_dir() . '/driftguard_scope_test_' . uniqid();
        mkdir($scopeDir, 0755, true);

        $corpoRuim = '$builder->whereRaw(\'1 = 0\'); return $builder;';

        $client = (new FakeAnalysisClient())
            ->enqueueProposeUpdate(['descricao' => 'x', 'notas' => 'y', 'escopo_tenant' => $corpoRuim])
            ->enqueueProposeUpdate(['descricao' => 'x', 'notas' => 'y', 'escopo_tenant' => $corpoRuim]);

        $service = $this->makeService(
            $client,
            fieldSpecs: [FieldSpec::scopeClass('escopo_tenant')->instructions('restrinja ao autor')],
            scopeClassWriter: new ScopeClassWriter(outputPath: $scopeDir, namespace: 'App\\DriftGuard\\Scopes'),
        );

        $result = $service->runAnalysis(['Post'], fn() => null);

        $this->assertCount(2, $client->mensagensRecebidas, 'não deve tentar uma 3ª vez');
        $this->assertSame($corpoRuim, $result['proposals']['Post']['escopo_tenant']);

        array_map('unlink', glob("{$scopeDir}/*.php") ?: []);
        @rmdir($scopeDir);
    }

    /**
     * Regra D2: arquivo do model acima de maxSnippetChars roda extractSafeParts() e grava o
     * ModelIndex — auditável (o índice guarda exatamente quais métodos foram considerados
     * relevantes) e reaproveitável na próxima rodada.
     */
    public function test_large_model_file_triggers_extraction_and_writes_index(): void
    {
        $client = (new FakeAnalysisClient())->enqueueProposeUpdate(['descricao' => 'x', 'notas' => 'y']);

        // maxSnippetChars minúsculo força a extração até no fixture pequeno (Post.php)
        $service = $this->makeService($client, maxSnippetChars: 5);

        $service->runAnalysis(['Post'], fn() => null);

        $indexPath = "{$this->tmpStorage}/index/Post.json";
        $this->assertFileExists($indexPath);

        $indice = json_decode(file_get_contents($indexPath), true);
        $this->assertContains('author', $indice['metodos_semente']);
        $this->assertArrayHasKey('hash_arquivo', $indice);
    }

    /** Regra D2: 2ª análise com o MESMO conteúdo de arquivo não deve regravar o índice (reaproveita). */
    public function test_second_analysis_with_unchanged_model_file_reuses_index_without_rewriting(): void
    {
        $client = (new FakeAnalysisClient())
            ->enqueueProposeUpdate(['descricao' => 'x', 'notas' => 'y'])
            ->enqueueProposeUpdate(['descricao' => 'x', 'notas' => 'y']);

        $service = $this->makeService($client, maxSnippetChars: 5);

        $service->runAnalysis(['Post'], fn() => null);

        $indexPath = "{$this->tmpStorage}/index/Post.json";
        $this->assertFileExists($indexPath);

        // "backdata" o mtime pra confirmar depois que NADA regravou o arquivo (regravar sempre
        // atualiza o mtime pro momento atual, então continuar com o mtime antigo prova reuso)
        touch($indexPath, time() - 1000);
        clearstatcache(true, $indexPath);
        $mtimeAntes = filemtime($indexPath);

        $service->runAnalysis(['Post'], fn() => null);

        clearstatcache(true, $indexPath);
        $this->assertSame($mtimeAntes, filemtime($indexPath), 'índice não deveria ser regravado quando o conteúdo do arquivo não mudou');
    }

    /**
     * Regra D3: orçamento total combinado nunca descarta o arquivo do PRÓPRIO model, mesmo sob
     * pressão extrema — só arquivo de apoio é candidato a descarte.
     */
    public function test_combined_budget_never_discards_the_model_file_even_under_extreme_pressure(): void
    {
        $dir = sys_get_temp_dir() . '/driftguard_budget_test_' . uniqid();
        mkdir($dir, 0755, true);
        file_put_contents("{$dir}/PostController.php", "<?php\nclass PostController {\n    public function x() {\n        return Post::all();\n    }\n}");

        $client = (new FakeAnalysisClient())->enqueueProposeUpdate(['descricao' => 'x', 'notas' => 'y']);

        $service = $this->makeService(
            $client,
            supportingPaths: [$dir],
            maxTotalSnippetChars: 1, // orçamento absurdamente pequeno — só o model deveria sobreviver
        );

        $service->runAnalysis(['Post'], fn() => null);

        $conteudoUsuario = $client->mensagensRecebidas[0][1]['content'];

        $this->assertStringContainsString('class Post extends Model', $conteudoUsuario);
        $this->assertStringNotContainsString('PostController', $conteudoUsuario);

        array_map('unlink', glob("{$dir}/*.php") ?: []);
        rmdir($dir);
    }

    /** Regra D4: limite de nº de arquivos de apoio é respeitado ponta a ponta. */
    public function test_supporting_file_count_cap_is_respected_end_to_end(): void
    {
        $dir = sys_get_temp_dir() . '/driftguard_count_cap_test_' . uniqid();
        mkdir($dir, 0755, true);
        for ($i = 1; $i <= 3; $i++) {
            file_put_contents("{$dir}/Controller{$i}.php", "<?php\nclass Controller{$i} {\n    public function x() {\n        return Post::all();\n    }\n}");
        }

        $client = (new FakeAnalysisClient())->enqueueProposeUpdate(['descricao' => 'x', 'notas' => 'y']);

        $service = $this->makeService($client, supportingPaths: [$dir], maxSupportingFiles: 1);

        $service->runAnalysis(['Post'], fn() => null);

        // o snippet extraído é só o corpo do método (sem a declaração "class ControllerN"), mas o
        // path do arquivo (que inclui o nome distinto) entra como cabeçalho de cada snippet — conta
        // quantos dos 3 nomes distintos aparecem, não o conteúdo (que é idêntico entre os 3 arquivos)
        $conteudoUsuario = $client->mensagensRecebidas[0][1]['content'];
        $qtdControllers  = 0;
        foreach ([1, 2, 3] as $i) {
            if (str_contains($conteudoUsuario, "Controller{$i}.php")) {
                $qtdControllers++;
            }
        }

        $this->assertSame(1, $qtdControllers, 'só 1 arquivo de apoio deveria ter entrado, respeitando maxSupportingFiles');

        array_map('unlink', glob("{$dir}/*.php") ?: []);
        rmdir($dir);
    }
}
