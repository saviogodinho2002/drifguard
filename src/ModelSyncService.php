<?php

namespace Saviogodinho2002\Drifguard;

use Saviogodinho2002\Drifguard\Contracts\AnalysisClient;
use Saviogodinho2002\Drifguard\Support\ConfigWriter;
use Saviogodinho2002\Drifguard\Support\ContextDocsResolver;
use Saviogodinho2002\Drifguard\Support\FieldSpec;
use Saviogodinho2002\Drifguard\Support\ModelDiscovery;
use Saviogodinho2002\Drifguard\Support\ModelReflector;
use Saviogodinho2002\Drifguard\Support\PromptBuilder;
use Saviogodinho2002\Drifguard\Support\ScopeClassWriter;

/**
 * Orquestra o fluxo completo: descoberta -> reflection -> prompt -> chamada ao LLM (loop de
 * tool-calling) -> proposta -> (noutro momento) merge/aplicação. Os 2 commands são só cascas finas
 * em cima disto.
 */
class ModelSyncService
{
    private const MAX_ANALYSIS_ITER = 4;

    /** Diretório que `request_file` pode ler — fora daqui, recusa (regra F). */
    private readonly string $allowedBasePath;

    public function __construct(
        private readonly AnalysisClient $client,
        private readonly ModelDiscovery $discovery,
        private readonly ModelReflector $reflector,
        private readonly ContextDocsResolver $contextDocs,
        private readonly ConfigWriter $configWriter,
        private readonly ?ScopeClassWriter $scopeClassWriter,
        /** @var FieldSpec[] */
        private readonly array $fieldSpecs,
        private readonly ?string $extraPromptRules,
        private readonly string $outputConfigPath,
        private readonly string $storagePath,
        /** Teto de tamanho (chars) pra um arquivo de apoio sem extração de método (regra D). */
        private readonly int $maxSnippetChars = 6000,
        ?string $allowedBasePath = null,
    ) {
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
        $this->allowedBasePath = $allowedBasePath ?? (getcwd() ?: '/');
    }

    // ── Descoberta ───────────────────────────────────────────────────────────

    /** @return string[] Todo model Eloquent descoberto (introspecção — regra E). */
    public function allModelNames(): array
    {
        return $this->discovery->allModelNames();
    }

    /** @return string[] Models sem entrada no config de saída. */
    public function findMissingModels(): array
    {
        $existentes = array_keys($this->readOutputConfig());
        return array_values(array_diff($this->discovery->allModelNames(), $existentes));
    }

    /**
     * Prévia sem chamar o LLM — pra `--dry-run` (regra B): quantos arquivos de apoio cada model
     * levaria e se tem doc de contexto resolvido.
     *
     * @param string[] $models
     * @return array<int, array{model: string, supporting_files: int, context_doc: ?string}>
     */
    public function previewFor(array $models): array
    {
        return array_map(function (string $modelo) {
            $doc = $this->contextDocs->resolveFor($modelo);
            return [
                'model'            => $modelo,
                'supporting_files' => count($this->discovery->supportingFilesForModel($modelo)),
                'context_doc'      => $doc['path'] ?? null,
            ];
        }, $models);
    }

    /** Doc de contexto resolvido pro model, ou null (introspecção — regra E). */
    public function contextDocFor(string $modelo): ?array
    {
        return $this->contextDocs->resolveFor($modelo);
    }

    /**
     * @param bool $force
     * @param string[] $modelsOption
     * @return array{mode: string, models: string[]}
     */
    public function resolveRunState(bool $force, array $modelsOption): array
    {
        if (!empty($modelsOption)) {
            return ['mode' => 'model', 'models' => $modelsOption];
        }

        $faltando = $this->findMissingModels();

        if ($force) {
            return ['mode' => 'force', 'models' => array_unique([...$this->discovery->allModelNames(), ...$faltando])];
        }

        $rerun   = $this->modelsAwaitingRerun();
        $mudados = $this->modelsChangedSinceLastRun();
        $todos   = array_values(array_unique([...$rerun, ...$mudados, ...$faltando]));

        return ['mode' => empty($rerun) ? 'diff' : 'rerun', 'models' => $todos];
    }

    /** @return string[] */
    private function modelsChangedSinceLastRun(): array
    {
        $ctx  = $this->readContext();
        $hash = $ctx['last_commit_hash'] ?? null;

        $range = $hash ? "{$hash}..HEAD" : 'HEAD~10..HEAD';
        $saida = [];
        exec('git diff --name-only ' . escapeshellarg($range) . ' 2>/dev/null', $saida);

        $modelos = [];
        foreach ($saida as $linha) {
            if (preg_match('#/([A-Za-z0-9_]+)\.php$#', $linha, $m) && in_array($m[1], $this->discovery->allModelNames(), true)) {
                $modelos[] = $m[1];
            }
        }
        return array_values(array_unique($modelos));
    }

    public function getCurrentCommitHash(): ?string
    {
        $saida = [];
        exec('git rev-parse HEAD 2>/dev/null', $saida);
        return $saida[0] ?? null;
    }

    // ── Análise (chamada ao LLM) ─────────────────────────────────────────────

    /**
     * @param string[] $models
     * @return array{proposals: array<string, array>, questions: array<int, array>}
     */
    public function runAnalysis(array $models, callable $onProgress): array
    {
        $proposals = [];
        $questions = [];
        $builder   = new PromptBuilder($this->fieldSpecs, $this->extraPromptRules);
        $respostas = $this->answeredQuestionsFor($models);

        foreach ($models as $modelo) {
            $metadata = $this->reflector->metadataFor($modelo);
            if ($metadata === null) {
                $onProgress($modelo);
                continue;
            }

            $snippets = $this->gatherSnippets($modelo);
            $contextDoc = $this->contextDocs->resolveFor($modelo);
            $respostaAnterior = $respostas[$modelo] ?? null;

            $messages = $builder->buildMessages($modelo, $snippets, $contextDoc, $metadata, $respostaAnterior);
            $tools    = $builder->buildTools();

            $resultado = $this->loopAnalise($messages, $tools, $snippets);

            if ($resultado['proposal'] !== null) {
                $proposals[$modelo] = array_merge($metadata, $resultado['proposal']);
            }
            foreach ($resultado['questions'] as $pergunta) {
                $questions[] = ['model' => $modelo, 'question' => $pergunta, 'answered' => false];
            }

            if ($respostaAnterior !== null) {
                $this->consumeAnsweredQuestions([$modelo]);
            }

            $onProgress($modelo);
        }

        if (!empty($questions)) {
            $this->recordQuestions($questions);
        }

        return ['proposals' => $proposals, 'questions' => $questions];
    }

    /** @return array{proposal: ?array, questions: string[]} */
    private function loopAnalise(array $messages, array $tools, array &$snippets): array
    {
        $camposEscopo = array_map(
            fn($s) => $s->name,
            array_values(array_filter($this->fieldSpecs, fn($s) => $s->type === FieldSpec::TYPE_SCOPE_CLASS))
        );
        $tentouCorrigirEscopo = false;

        for ($i = 0; $i < self::MAX_ANALYSIS_ITER; $i++) {
            $resposta  = $this->client->chat($messages, $tools);
            $toolCalls = $resposta['tool_calls'] ?? [];

            if (empty($toolCalls)) {
                return ['proposal' => null, 'questions' => []];
            }

            foreach ($toolCalls as $chamada) {
                $nome = $chamada['function']['name'] ?? '';
                $args = json_decode($chamada['function']['arguments'] ?? '{}', true) ?? [];

                if ($nome === 'propose_update') {
                    if (!$tentouCorrigirEscopo && !empty($camposEscopo) && $this->scopeClassWriter !== null) {
                        $erroEscopo = $this->erroDeEscopoEm($args, $camposEscopo);
                        if ($erroEscopo !== null) {
                            $tentouCorrigirEscopo = true;
                            $messages[] = ['role' => 'assistant', 'content' => null, 'tool_calls' => $toolCalls];
                            $messages[] = ['role' => 'tool', 'tool_call_id' => $chamada['id'] ?? '', 'content' => $erroEscopo];
                            continue 2;
                        }
                    }

                    // sanitiza formato (fences/assinatura/use) mesmo quando passou de primeira —
                    // proposal.php e o diff de revisão mostram o corpo já limpo, não o bruto.
                    foreach ($camposEscopo as $campo) {
                        if (!empty($args[$campo]) && $this->scopeClassWriter !== null) {
                            $args[$campo] = $this->scopeClassWriter->sanitize($args[$campo]);
                        }
                    }

                    return ['proposal' => $args, 'questions' => []];
                }

                if ($nome === 'ask_question') {
                    return ['proposal' => null, 'questions' => [$args['question'] ?? '']];
                }

                if ($nome === 'request_file') {
                    $path = $args['path'] ?? '';
                    $conteudo = $this->lerArquivoComGuarda($path);
                    $snippets[$path] = $conteudo;
                    $messages[] = ['role' => 'assistant', 'content' => null, 'tool_calls' => $toolCalls];
                    $messages[] = ['role' => 'tool', 'tool_call_id' => $chamada['id'] ?? '', 'content' => $conteudo];
                }
            }
        }

        return ['proposal' => null, 'questions' => ['Análise excedeu o limite de iterações sem propor nem perguntar.']];
    }

    /**
     * Valida os campos scope_class de uma proposta ANTES de aceitá-la — pega o mesmo tipo de erro
     * que `ScopeClassWriter::write()` pegaria em `apply()`, mas em tempo de análise, com a conversa
     * (mensagens/snippets) ainda disponível pra pedir 1 correção com contexto. Só 1 tentativa por
     * model (`$tentouCorrigirEscopo` no chamador) — se ainda falhar depois, `apply()` continua
     * sendo o backstop final (rejeita, não escreve, não corrompe nada).
     *
     * @param string[] $camposEscopo
     * @return string|null Mensagem de correção pro 1º campo inválido encontrado, ou null se todos passarem.
     */
    private function erroDeEscopoEm(array $args, array $camposEscopo): ?string
    {
        foreach ($camposEscopo as $campo) {
            if (empty($args[$campo])) {
                continue;
            }

            $erro = $this->scopeClassWriter->validar($args[$campo]);
            if ($erro !== null) {
                return "O campo '{$campo}' não passou na validação (formato ou uso de variáveis): {$erro}\n"
                    . FieldSpec::SCOPE_CLASS_FORMAT_CONTRACT
                    . "\nChame propose_update de novo com o campo '{$campo}' corrigido.";
            }
        }

        return null;
    }

    /**
     * Lê um arquivo pedido pela IA via `request_file`, restrito a `allowedBasePath` (regra F) — sem
     * essa guarda, um path tipo `../../.env` seria lido sem checagem nenhuma. Recusa nunca é
     * silenciosa: a mensagem de recusa vira o próprio conteúdo do `tool` message, pra IA ver e não
     * insistir cegamente no mesmo path.
     */
    private function lerArquivoComGuarda(string $path): string
    {
        if ($path === '' || !is_file($path)) {
            return "Arquivo não encontrado: {$path}";
        }

        $arquivoReal = realpath($path);
        $baseReal    = realpath($this->allowedBasePath);

        $dentroDaBase = $arquivoReal !== false && $baseReal !== false
            && ($arquivoReal === $baseReal || str_starts_with($arquivoReal, rtrim($baseReal, '/') . '/'));

        if (!$dentroDaBase) {
            return "Acesso negado: '{$path}' está fora do diretório permitido ({$this->allowedBasePath}).";
        }

        return file_get_contents($arquivoReal) ?: '';
    }

    /** @return array<string, string> caminho => conteúdo */
    private function gatherSnippets(string $modelo): array
    {
        $snippets = [];

        // arquivo do próprio model é a fonte primária — sempre inteiro, nunca truncado (regra D)
        $modelPath = $this->discovery->modelFilePath($modelo);
        if ($modelPath) {
            $snippets[$modelPath] = file_get_contents($modelPath) ?: '';
        }

        foreach ($this->discovery->supportingFilesForModel($modelo) as $path) {
            $snippets[$path] = $this->snippetDeApoio($path, $modelo);
        }

        return $snippets;
    }

    /**
     * Arquivo de apoio (controller/service): tenta extrair só os métodos relevantes primeiro; se
     * não achar nenhum, cai pro conteúdo integral truncado em `maxSnippetChars` — nunca estoura sem
     * avisar (regra D).
     */
    private function snippetDeApoio(string $path, string $modelo): string
    {
        $extraido = $this->discovery->extractRelevantMethods($path, $modelo);
        if ($extraido !== null) {
            return $extraido;
        }

        $conteudo = file_get_contents($path) ?: '';
        if (mb_strlen($conteudo) > $this->maxSnippetChars) {
            return mb_substr($conteudo, 0, $this->maxSnippetChars) . "\n... (truncado)";
        }

        return $conteudo;
    }

    // ── Persistência (context.json / proposal.php / questions.md) ───────────

    public function storagePath(string $arquivo = ''): string
    {
        return rtrim($this->storagePath, '/') . ($arquivo ? "/{$arquivo}" : '');
    }

    public function readContext(): array
    {
        $path = $this->storagePath('context.json');
        if (!is_file($path)) {
            return [];
        }
        return json_decode(file_get_contents($path) ?: '{}', true) ?? [];
    }

    public function writeContext(array $ctx): void
    {
        file_put_contents($this->storagePath('context.json'), json_encode($ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, array> $proposals */
    public function writeProposal(array $proposals, array $ctx): void
    {
        $conteudo = "<?php\n\nreturn " . var_export($proposals, true) . ";\n";
        file_put_contents($this->storagePath('proposal.php'), $conteudo);
    }

    public function readProposal(): array
    {
        $path = $this->storagePath('proposal.php');
        if (!is_file($path)) {
            return [];
        }
        return include $path;
    }

    public function clearProposal(): void
    {
        @unlink($this->storagePath('proposal.php'));
    }

    public function writeQuestions(array $questions, array $ctx): void
    {
        $linhas = ["# Perguntas pendentes\n"];
        foreach ($questions as $q) {
            $linhas[] = "## {$q['model']}\n\n{$q['question']}\n\n> Resposta: \n";
        }
        file_put_contents($this->storagePath('questions.md'), implode("\n", $linhas));
    }

    // ── Perguntas pendentes (context.json) — fecha o gap de paridade com o modo `rerun` original ──

    /** @param array<int, array{model: string, question: string, answered: bool}> $questions */
    private function recordQuestions(array $questions): void
    {
        $ctx = $this->readContext();
        $ctx['pending_questions'] = array_merge($ctx['pending_questions'] ?? [], array_map(
            fn($q) => ['model' => $q['model'], 'question' => $q['question'], 'answered' => false, 'answer' => null],
            $questions
        ));
        $this->writeContext($ctx);
    }

    /**
     * Marca a última pergunta não-respondida de $modelo como respondida — usado por
     * `drifguard:answer`, alternativa a editar `questions.md` à mão.
     *
     * @return array{found: bool, question: ?string}
     */
    public function answerQuestion(string $modelo, string $resposta): array
    {
        $ctx    = $this->readContext();
        $lista  = $ctx['pending_questions'] ?? [];
        $indice = null;

        foreach ($lista as $i => $q) {
            if ($q['model'] === $modelo && !$q['answered']) {
                $indice = $i;
            }
        }

        if ($indice === null) {
            return ['found' => false, 'question' => null];
        }

        $lista[$indice]['answered'] = true;
        $lista[$indice]['answer']   = $resposta;
        $ctx['pending_questions']   = $lista;
        $this->writeContext($ctx);

        return ['found' => true, 'question' => $lista[$indice]['question']];
    }

    /** @return string[] Models com pergunta respondida (via drifguard:answer) mas ainda não reanalisados. */
    public function modelsAwaitingRerun(): array
    {
        $ctx    = $this->readContext();
        $models = [];
        foreach ($ctx['pending_questions'] ?? [] as $q) {
            if ($q['answered']) {
                $models[] = $q['model'];
            }
        }
        return array_values(array_unique($models));
    }

    /**
     * @param string[] $models
     * @return array<string, array{question: string, answer: string}> última resposta por model —
     *         alimenta o prompt da próxima análise (regra C).
     */
    private function answeredQuestionsFor(array $models): array
    {
        $ctx = $this->readContext();
        $out = [];
        foreach ($ctx['pending_questions'] ?? [] as $q) {
            if ($q['answered'] && in_array($q['model'], $models, true)) {
                $out[$q['model']] = ['question' => $q['question'], 'answer' => $q['answer']];
            }
        }
        return $out;
    }

    /** @param string[] $models Remove da fila as perguntas já respondidas e consumidas nesta análise. */
    private function consumeAnsweredQuestions(array $models): void
    {
        $ctx = $this->readContext();
        $ctx['pending_questions'] = array_values(array_filter(
            $ctx['pending_questions'] ?? [],
            fn($q) => !(in_array($q['model'], $models, true) && $q['answered'])
        ));
        $this->writeContext($ctx);
    }

    // ── Aplicação (merge + escrita do config de saída) ───────────────────────

    /** @return array<string, array> */
    public function readOutputConfig(): array
    {
        if (!is_file($this->outputConfigPath)) {
            return [];
        }
        return include $this->outputConfigPath;
    }

    /**
     * @return array<int, array{model: string, campo: string, tipo: string, detalhe: string}>
     */
    public function buildDiff(array $current, array $proposal): array
    {
        $linhas = [];
        foreach ($proposal as $modelo => $entrada) {
            $existente = $current[$modelo] ?? null;
            foreach ($entrada as $campo => $valor) {
                if ($valor === null || $valor === '') {
                    continue;
                }
                $atual = $existente[$campo] ?? null;
                if ($atual === $valor) {
                    continue;
                }
                $tipo = $existente === null ? 'NOVO' : ($atual === null ? 'adicionado' : 'alterado');
                $linhas[] = [
                    'model'   => $modelo,
                    'campo'   => $campo,
                    'tipo'    => $tipo,
                    'detalhe' => mb_substr((string) $valor, 0, 80),
                ];
            }
        }
        return $linhas;
    }

    /**
     * Aplica a proposta: resolve campos scope_class via ScopeClassWriter (gera arquivo de classe,
     * config guarda só o FQCN), demais campos vão pro merge normal do ConfigWriter.
     *
     * @return array{merged: array<string, array>, scopeResults: array<int, array>}
     */
    public function apply(array $current, array $proposal): array
    {
        $ctx           = $this->readContext();
        $scopeResults  = [];
        $proposalFinal = $proposal;

        foreach ($this->fieldSpecs as $spec) {
            if ($spec->type !== FieldSpec::TYPE_SCOPE_CLASS || $this->scopeClassWriter === null) {
                continue;
            }

            foreach ($proposal as $modelo => $entrada) {
                if (empty($entrada[$spec->name])) {
                    continue;
                }

                $hashAnterior = $ctx['scope_hashes'][$modelo][$spec->name] ?? null;
                $resultado    = $this->scopeClassWriter->write($modelo, $entrada[$spec->name], $hashAnterior);
                $resultado['model'] = $modelo;
                $resultado['field'] = $spec->name;
                $scopeResults[] = $resultado;

                if ($resultado['status'] === 'written') {
                    $proposalFinal[$modelo][$spec->name] = $this->scopeClassWriter->fqcnFor($modelo);
                    $ctx['scope_hashes'][$modelo][$spec->name] = $resultado['hash'];
                } else {
                    // não sobrescreve o valor de config existente quando pula/erra
                    unset($proposalFinal[$modelo][$spec->name]);
                }
            }
        }

        $this->writeContext($ctx);

        $merged = $this->configWriter->mergeProposal($current, $proposalFinal);
        $this->configWriter->write($this->outputConfigPath, $merged, $this->fieldSpecs);

        return ['merged' => $merged, 'scopeResults' => $scopeResults];
    }
}
