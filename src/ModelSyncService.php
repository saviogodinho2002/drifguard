<?php

namespace Saviogodinho2002\DriftGuard;

use Saviogodinho2002\DriftGuard\Contracts\AnalysisClient;
use Saviogodinho2002\DriftGuard\Support\ConfigWriter;
use Saviogodinho2002\DriftGuard\Support\ContextDocsResolver;
use Saviogodinho2002\DriftGuard\Support\FieldSpec;
use Saviogodinho2002\DriftGuard\Support\ModelDiscovery;
use Saviogodinho2002\DriftGuard\Support\ModelIndex;
use Saviogodinho2002\DriftGuard\Support\ModelReflector;
use Saviogodinho2002\DriftGuard\Support\PromptBuilder;
use Saviogodinho2002\DriftGuard\Support\ScopeClassWriter;

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

    /** Índice persistido por model — evita recomputar `extractSafeParts()` quando o arquivo não mudou. */
    private readonly ModelIndex $modelIndex;

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
        /** Soma de TODOS os snippets de 1 model (model + apoio) — nunca descarta o arquivo do model. */
        private readonly int $maxTotalSnippetChars = 60000,
        /** Nº máximo de arquivos de apoio coletados por model. */
        private readonly int $maxSupportingFiles = 5,
    ) {
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
        $this->allowedBasePath = $allowedBasePath ?? (getcwd() ?: '/');
        $this->modelIndex = new ModelIndex($this->storagePath);
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
     * @param bool $analiseCompleta Ignora `maxSnippetChars`/`maxTotalSnippetChars`/`maxSupportingFiles`
     *        pra esta rodada — manda tudo inteiro, sem extração/truncamento/corte de arquivo de
     *        apoio (regra `--full`). Só afeta QUANTO CONTEÚDO de cada model entra, nunca QUAIS
     *        models são analisados (isso continua sendo decidido por `resolveRunState()`).
     * @return array{proposals: array<string, array>, questions: array<int, array>, usage: array{cost_usd: ?float, prompt_tokens: ?int, completion_tokens: ?int}}
     */
    public function runAnalysis(array $models, callable $onProgress, bool $analiseCompleta = false): array
    {
        $proposals = [];
        $questions = [];
        $usageTotal = $this->usageVazio();
        $builder   = new PromptBuilder($this->fieldSpecs, $this->extraPromptRules);
        $respostas = $this->answeredQuestionsFor($models);

        foreach ($models as $modelo) {
            $metadata = $this->reflector->metadataFor($modelo);
            if ($metadata === null) {
                $onProgress($modelo);
                continue;
            }

            $snippets = $this->gatherSnippets($modelo, $analiseCompleta);
            $contextDoc = $this->contextDocs->resolveFor($modelo);
            $respostaAnterior = $respostas[$modelo] ?? null;

            $messages = $builder->buildMessages($modelo, $snippets, $contextDoc, $metadata, $respostaAnterior);
            $tools    = $builder->buildTools();

            $resultado = $this->loopAnalise($messages, $tools, $snippets);
            $usageTotal = $this->somarUsage($usageTotal, $resultado['usage']);

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

        return ['proposals' => $proposals, 'questions' => $questions, 'usage' => $usageTotal];
    }

    /** @return array{cost_usd: ?float, prompt_tokens: ?int, completion_tokens: ?int} */
    private function usageVazio(): array
    {
        return ['cost_usd' => null, 'prompt_tokens' => null, 'completion_tokens' => null];
    }

    /**
     * Soma null-safe: parcela `null` não participa da soma (não vira 0); se as 2 parcelas de um
     * campo forem `null`, o total continua `null` (nunca inventa dado que nenhum provedor expôs).
     *
     * @param array{cost_usd: ?float, prompt_tokens: ?int, completion_tokens: ?int} $a
     * @param array{cost_usd: ?float, prompt_tokens: ?int, completion_tokens: ?int} $b
     * @return array{cost_usd: ?float, prompt_tokens: ?int, completion_tokens: ?int}
     */
    private function somarUsage(array $a, array $b): array
    {
        $somarCampo = function ($x, $y) {
            if ($x === null && $y === null) {
                return null;
            }
            return ($x ?? 0) + ($y ?? 0);
        };

        return [
            'cost_usd'          => $somarCampo($a['cost_usd'] ?? null, $b['cost_usd'] ?? null),
            'prompt_tokens'     => $somarCampo($a['prompt_tokens'] ?? null, $b['prompt_tokens'] ?? null),
            'completion_tokens' => $somarCampo($a['completion_tokens'] ?? null, $b['completion_tokens'] ?? null),
        ];
    }

    /** @return array{proposal: ?array, questions: string[], usage: array{cost_usd: ?float, prompt_tokens: ?int, completion_tokens: ?int}} */
    private function loopAnalise(array $messages, array $tools, array &$snippets): array
    {
        $camposEscopo = array_map(
            fn($s) => $s->name,
            array_values(array_filter($this->fieldSpecs, fn($s) => $s->type === FieldSpec::TYPE_SCOPE_CLASS))
        );
        $tentouCorrigirEscopo = false;
        $usageAcumulado = $this->usageVazio();

        for ($i = 0; $i < self::MAX_ANALYSIS_ITER; $i++) {
            $resposta  = $this->client->chat($messages, $tools);
            $usageAcumulado = $this->somarUsage($usageAcumulado, $resposta['usage'] ?? $this->usageVazio());
            $toolCalls = $resposta['tool_calls'] ?? [];

            if (empty($toolCalls)) {
                return ['proposal' => null, 'questions' => [], 'usage' => $usageAcumulado];
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

                    return ['proposal' => $args, 'questions' => [], 'usage' => $usageAcumulado];
                }

                if ($nome === 'ask_question') {
                    return ['proposal' => null, 'questions' => [$args['question'] ?? ''], 'usage' => $usageAcumulado];
                }

                if ($nome === 'request_file') {
                    $path = $args['path'] ?? '';
                    $conteudo = $this->lerArquivoComGuarda($path);
                    $snippets[$path] = $conteudo;
                    $messages[] = ['role' => 'assistant', 'content' => null, 'tool_calls' => $toolCalls];
                    $messages[] = ['role' => 'tool', 'tool_call_id' => $chamada['id'] ?? '', 'content' => $conteudo];
                }

                if ($nome === 'request_method') {
                    $porArquivo = [];
                    foreach ($args['requests'] ?? [] as $pedido) {
                        $porArquivo[$pedido['path'] ?? '']['metodos'][] = $pedido['method'] ?? '';
                    }

                    $conteudoTotal = [];
                    foreach ($porArquivo as $path => ['metodos' => $metodos]) {
                        $conteudo = $this->extrairMetodosComGuarda($path, $metodos);
                        $snippets["{$path} (métodos pedidos: " . implode(', ', $metodos) . ')'] = $conteudo;
                        $conteudoTotal[] = "--- {$path} ---\n{$conteudo}";
                    }

                    $messages[] = ['role' => 'assistant', 'content' => null, 'tool_calls' => $toolCalls];
                    $messages[] = ['role' => 'tool', 'tool_call_id' => $chamada['id'] ?? '', 'content' => implode("\n\n", $conteudoTotal)];
                }
            }
        }

        return ['proposal' => null, 'questions' => ['Análise excedeu o limite de iterações sem propor nem perguntar.'], 'usage' => $usageAcumulado];
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
        $arquivoReal = $this->resolverDentroDaBase($path);
        if ($arquivoReal === null) {
            return $path === '' || !is_file($path)
                ? "Arquivo não encontrado: {$path}"
                : "Acesso negado: '{$path}' está fora do diretório permitido ({$this->allowedBasePath}).";
        }

        return file_get_contents($arquivoReal) ?: '';
    }

    /**
     * Pede o corpo de métodos ESPECÍFICOS de um arquivo (item 1, `request_method`) — mesma guarda
     * de `allowedBasePath` que `lerArquivoComGuarda()` já aplica, mas devolve só os métodos
     * pedidos (via `ModelDiscovery::extractNamedMethods()`, já usado pelo `ModelIndex`) em vez do
     * arquivo inteiro. Método pedido que não existe no arquivo nunca falha silenciosamente — a
     * ausência vira parte da mensagem devolvida, pra IA não achar que recebeu algo que não veio.
     *
     * @param string[] $metodos
     */
    private function extrairMetodosComGuarda(string $path, array $metodos): string
    {
        $arquivoReal = $this->resolverDentroDaBase($path);
        if ($arquivoReal === null) {
            return $path === '' || !is_file($path)
                ? "Arquivo não encontrado: {$path}"
                : "Acesso negado: '{$path}' está fora do diretório permitido ({$this->allowedBasePath}).";
        }

        $conteudo = file_get_contents($arquivoReal) ?: '';
        $corpos   = $this->discovery->extractNamedMethods($conteudo, $metodos);

        $partes = [];
        if (!empty($corpos)) {
            $partes[] = implode("\n\n", $corpos);
        }

        $faltando = array_values(array_diff($metodos, array_keys($corpos)));
        if (!empty($faltando)) {
            $partes[] = 'Método(s) não encontrado(s) em ' . $path . ': ' . implode(', ', $faltando);
        }

        return implode("\n\n", $partes);
    }

    /** @return string|null Path real (resolvido) se dentro de `allowedBasePath`, ou null se recusado/inexistente. */
    private function resolverDentroDaBase(string $path): ?string
    {
        if ($path === '' || !is_file($path)) {
            return null;
        }

        $arquivoReal = realpath($path);
        $baseReal    = realpath($this->allowedBasePath);

        $dentroDaBase = $arquivoReal !== false && $baseReal !== false
            && ($arquivoReal === $baseReal || str_starts_with($arquivoReal, rtrim($baseReal, '/') . '/'));

        return $dentroDaBase ? $arquivoReal : null;
    }

    /** @return array<string, string> caminho => conteúdo */
    private function gatherSnippets(string $modelo, bool $analiseCompleta = false): array
    {
        $snippets  = [];
        $modelPath = $this->discovery->modelFilePath($modelo);

        // arquivo do próprio model é a fonte primária — nunca descartado no orçamento total, e só
        // sofre extração/truncamento quando realmente grande (regra D2).
        if ($modelPath) {
            $conteudo = file_get_contents($modelPath) ?: '';
            $snippets[$modelPath] = $this->snippetDoModel($modelo, $conteudo, $analiseCompleta);
        }

        $maxArquivos = $analiseCompleta ? PHP_INT_MAX : $this->maxSupportingFiles;
        foreach ($this->discovery->supportingFilesForModel($modelo, $maxArquivos) as $path) {
            $snippets[$path] = $this->snippetDeApoio($path, $modelo, $analiseCompleta);
        }

        return $this->aplicarOrcamentoTotal($snippets, $modelPath, $analiseCompleta);
    }

    /**
     * Arquivo do próprio model: só extrai/trunca quando maior que `maxSnippetChars` — pequeno
     * continua indo inteiro, sem custo/risco de extração (regra D2). Extração SEGURA (mantém todo
     * método público, só remove overrides do Eloquent — ver `ModelDiscovery::extractSafeParts()`
     * pro porquê da versão agressiva ter sido descartada). Reaproveita `ModelIndex` quando o
     * conteúdo não mudou desde a última rodada, em vez de reclassificar tudo de novo.
     */
    private function snippetDoModel(string $modelo, string $conteudo, bool $analiseCompleta = false): string
    {
        if ($analiseCompleta || mb_strlen($conteudo) <= $this->maxSnippetChars) {
            return $conteudo;
        }

        if (!$this->modelIndex->isStale($modelo, $conteudo)) {
            $indice = $this->modelIndex->read($modelo);
            $corpos = $this->discovery->extractNamedMethods($conteudo, $indice['metodos_semente']);
        } else {
            $resultado = $this->discovery->extractSafeParts($conteudo);
            $corpos    = $resultado['corpos'];
            $this->modelIndex->write($modelo, [
                'hash_arquivo'      => hash('sha256', $conteudo),
                'atualizado_em'     => date(DATE_ATOM),
                'metodos_semente'   => $resultado['semente'],
                'tamanho_arquivo'   => strlen($conteudo),
                'tamanho_extraido'  => strlen(implode("\n\n", $corpos)),
            ]);
        }

        return $this->discovery->packWithinBudget($corpos, $this->maxSnippetChars);
    }

    /**
     * Arquivo de apoio (controller/service): tenta extrair só os métodos relevantes primeiro; se
     * não achar nenhum, cai pro conteúdo integral truncado em `maxSnippetChars` — nunca estoura sem
     * avisar (regra D).
     */
    private function snippetDeApoio(string $path, string $modelo, bool $analiseCompleta = false): string
    {
        $conteudo = file_get_contents($path) ?: '';
        if ($analiseCompleta) {
            return $conteudo;
        }

        $extraido = $this->discovery->extractRelevantMethods($path, $modelo);
        if ($extraido !== null) {
            return $extraido;
        }

        if (mb_strlen($conteudo) > $this->maxSnippetChars) {
            return mb_substr($conteudo, 0, $this->maxSnippetChars) . "\n... (truncado)";
        }

        return $conteudo;
    }

    /**
     * Orçamento total combinado (regra D3, espelha MAX_TOTAL_CHARS do sistema original): se a soma
     * de todos os snippets de 1 model estourar, descarta arquivo de APOIO (nunca o do model)
     * começando pelo de MENOR conteúdo — sinal de que teve menos relevância extraída dele.
     *
     * @param array<string, string> $snippets
     * @return array<string, string>
     */
    private function aplicarOrcamentoTotal(array $snippets, ?string $modelPath, bool $analiseCompleta = false): array
    {
        if ($analiseCompleta) {
            return $snippets;
        }

        $total = array_sum(array_map('mb_strlen', $snippets));
        if ($total <= $this->maxTotalSnippetChars) {
            return $snippets;
        }

        $apoio = $snippets;
        if ($modelPath !== null) {
            unset($apoio[$modelPath]);
        }
        uasort($apoio, fn($a, $b) => mb_strlen($a) <=> mb_strlen($b));

        foreach (array_keys($apoio) as $path) {
            if ($total <= $this->maxTotalSnippetChars) {
                break;
            }
            $total -= mb_strlen($snippets[$path]);
            unset($snippets[$path]);
        }

        return $snippets;
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

    /**
     * Mescla com o que já existe em `proposal.php` (não sobrescreve) — sem isso, uma 2ª `analyze`
     * pra outros models descartava em silêncio a proposta de uma 1ª rodada ainda não aplicada
     * (`driftguard:apply`). Model repetido nos 2 lados: a versão desta rodada vence (reflete o
     * código mais atual); model só na proposta antiga é preservado.
     *
     * @param array<string, array> $proposals
     */
    public function writeProposal(array $proposals, array $ctx): void
    {
        $mesclado = array_merge($this->readProposal(), $proposals);
        $conteudo = "<?php\n\nreturn " . var_export($mesclado, true) . ";\n";
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
     * `driftguard:answer`, alternativa a editar `questions.md` à mão.
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

    /** @return string[] Models com pergunta respondida (via driftguard:answer) mas ainda não reanalisados. */
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
                $resultado    = $this->scopeClassWriter->write($modelo, $spec->name, $entrada[$spec->name], $hashAnterior);
                $resultado['model'] = $modelo;
                $resultado['field'] = $spec->name;
                $scopeResults[] = $resultado;

                if ($resultado['status'] === 'written') {
                    $proposalFinal[$modelo][$spec->name] = $this->scopeClassWriter->fqcnFor($modelo, $spec->name);
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
