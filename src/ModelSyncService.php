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
    ) {
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
    }

    // ── Descoberta ───────────────────────────────────────────────────────────

    /** @return string[] Models sem entrada no config de saída. */
    public function findMissingModels(): array
    {
        $existentes = array_keys($this->readOutputConfig());
        return array_values(array_diff($this->discovery->allModelNames(), $existentes));
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

        $mudados = $this->modelsChangedSinceLastRun();

        return ['mode' => 'diff', 'models' => array_values(array_unique([...$mudados, ...$faltando]))];
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

        foreach ($models as $modelo) {
            $metadata = $this->reflector->metadataFor($modelo);
            if ($metadata === null) {
                $onProgress($modelo);
                continue;
            }

            $snippets = $this->gatherSnippets($modelo);
            $contextDoc = $this->contextDocs->resolveFor($modelo);

            $messages = $builder->buildMessages($modelo, $snippets, $contextDoc, $metadata);
            $tools    = $builder->buildTools();

            $resultado = $this->loopAnalise($messages, $tools, $snippets);

            if ($resultado['proposal'] !== null) {
                $proposals[$modelo] = array_merge($metadata, $resultado['proposal']);
            }
            foreach ($resultado['questions'] as $pergunta) {
                $questions[] = ['model' => $modelo, 'question' => $pergunta, 'answered' => false];
            }

            $onProgress($modelo);
        }

        return ['proposals' => $proposals, 'questions' => $questions];
    }

    /** @return array{proposal: ?array, questions: string[]} */
    private function loopAnalise(array $messages, array $tools, array &$snippets): array
    {
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
                    return ['proposal' => $args, 'questions' => []];
                }

                if ($nome === 'ask_question') {
                    return ['proposal' => null, 'questions' => [$args['question'] ?? '']];
                }

                if ($nome === 'request_file') {
                    $path = $args['path'] ?? '';
                    $conteudo = is_file($path) ? file_get_contents($path) : "Arquivo não encontrado: {$path}";
                    $snippets[$path] = $conteudo;
                    $messages[] = ['role' => 'assistant', 'content' => null, 'tool_calls' => $toolCalls];
                    $messages[] = ['role' => 'tool', 'tool_call_id' => $chamada['id'] ?? '', 'content' => $conteudo];
                }
            }
        }

        return ['proposal' => null, 'questions' => ['Análise excedeu o limite de iterações sem propor nem perguntar.']];
    }

    /** @return array<string, string> caminho => conteúdo */
    private function gatherSnippets(string $modelo): array
    {
        $snippets = [];

        $modelPath = $this->discovery->modelFilePath($modelo);
        if ($modelPath) {
            $snippets[$modelPath] = file_get_contents($modelPath) ?: '';
        }

        foreach ($this->discovery->supportingFilesForModel($modelo) as $path) {
            $snippets[$path] = file_get_contents($path) ?: '';
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
