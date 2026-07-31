<?php

namespace Saviogodinho2002\DriftGuard\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Saviogodinho2002\DriftGuard\Support\FieldSpec;
use Saviogodinho2002\DriftGuard\Support\ModelDiscovery;

/**
 * Valida config('driftguard.*') sem chamar o LLM (regra A) — pra pegar campo malformado, path
 * inexistente/não-gravável ou chave de API ausente ANTES de rodar driftguard:analyze de verdade.
 */
class DoctorCommand extends Command
{
    protected $signature = 'driftguard:doctor {--json : Saída em JSON em vez de tabela}';

    protected $description = 'Valida a config do driftguard (fields, paths, chave de API) sem chamar o LLM';

    public function handle(): int
    {
        $config  = config('driftguard', []);
        $checks  = [];
        $falhou  = false;

        $fieldSpecs = [];
        foreach ($config['fields'] ?? [] as $i => $spec) {
            try {
                $fieldSpecs[] = $spec instanceof FieldSpec ? $spec : FieldSpec::fromArray($spec);
                $checks[] = ['PASS', "fields[{$i}]", 'FieldSpec válido'];
            } catch (InvalidArgumentException $e) {
                $checks[] = ['FAIL', "fields[{$i}]", $e->getMessage()];
                $falhou = true;
            }
        }

        // Nome de field duplicado sobrescreve silenciosamente o anterior no schema de tool-calling
        // (PromptBuilder::buildTools() monta $properties[$spec->name] = ... num array simples) —
        // achado real ao investigar a colisão de scope_class no mesmo model, mas se aplica a
        // qualquer tipo de field, não só scope_class.
        $nomesFields = array_map(fn($s) => $s->name, $fieldSpecs);
        foreach (array_filter(array_count_values($nomesFields), fn($qtd) => $qtd > 1) as $nome => $qtd) {
            $checks[] = ['FAIL', 'fields', "nome '{$nome}' duplicado ({$qtd}x) — cada FieldSpec precisa de nome único: um nome repetido sobrescreve o anterior no schema de tool-calling, e (se for scope_class) colide no mesmo arquivo/classe gerado."];
            $falhou = true;
        }

        $modelsPath = $config['models_path'] ?? null;
        if ($modelsPath && is_dir($modelsPath)) {
            $checks[] = ['PASS', 'models_path', $modelsPath];
        } else {
            $checks[] = ['FAIL', 'models_path', "diretório não encontrado: {$modelsPath}"];
            $falhou = true;
        }

        // Estudado a partir do docudoodle (github.com/genericmilk/docudoodle): ele apaga o .md
        // órfão sozinho quando o source some — não dá pra fazer isso aqui (config/models.php é
        // curado à mão, "campo que saiu da spec é preservado, nunca apagado silenciosamente" já é
        // regra documentada), então só REPORTA — a decisão fica com um humano. Construído direto
        // (sem passar por ModelSyncService, que constrói FieldSpec eagerly no factory do container
        // — um field malformado já quebraria a resolução ANTES do try/catch acima rodar) pra doctor
        // continuar seguro de rodar mesmo com config quebrada em outro lugar.
        if ($modelsPath && is_dir($modelsPath)) {
            $discovery = new ModelDiscovery(
                modelsPath: $modelsPath,
                modelNamespace: $config['model_namespace'] ?? 'App\\Models',
                supportingPaths: [],
            );
            $descobertos = $discovery->allModelNames();

            $outputPathParaOrfaos = $config['output_path'] ?? '';
            $catalogados = ($outputPathParaOrfaos && is_file($outputPathParaOrfaos))
                ? array_keys((array) include $outputPathParaOrfaos)
                : [];

            $orfaos = array_values(array_diff($catalogados, $descobertos));
            foreach ($orfaos as $modelo) {
                $checks[] = ['WARN', 'catálogo', "'{$modelo}' está em " . ($outputPathParaOrfaos ?: 'config/models.php')
                    . " mas não existe mais em models_path — model renomeado/movido/apagado? revise manualmente."];
            }
        }

        foreach ($config['supporting_paths'] ?? [] as $path) {
            $checks[] = is_dir($path)
                ? ['PASS', 'supporting_paths', $path]
                : ['WARN', 'supporting_paths', "diretório não encontrado: {$path}"];
        }

        $outputPath = $config['output_path'] ?? '';
        $outputDir  = $outputPath ? dirname($outputPath) : '';
        if ($outputDir && is_dir($outputDir) && is_writable($outputDir)) {
            $checks[] = ['PASS', 'output_path', $outputPath];
        } else {
            $checks[] = ['FAIL', 'output_path', "diretório de saída não gravável: {$outputDir}"];
            $falhou = true;
        }

        $storagePath = $config['storage_path'] ?? null;
        if ($storagePath && (is_dir($storagePath) || @mkdir($storagePath, 0755, true))) {
            $checks[] = ['PASS', 'storage_path', $storagePath];
        } else {
            $checks[] = ['FAIL', 'storage_path', "não existe e não pôde ser criado: {$storagePath}"];
            $falhou = true;
        }

        $temScopeClass = !empty(array_filter($fieldSpecs, fn($s) => $s->type === FieldSpec::TYPE_SCOPE_CLASS));
        if ($temScopeClass) {
            $scopePath      = $config['scope_class_path'] ?? null;
            $scopeNamespace = $config['scope_class_namespace'] ?? null;

            if ($scopePath && (is_dir($scopePath) || @mkdir($scopePath, 0755, true))) {
                $checks[] = ['PASS', 'scope_class_path', $scopePath];
            } else {
                $checks[] = ['FAIL', 'scope_class_path', "não existe e não pôde ser criado: {$scopePath}"];
                $falhou = true;
            }

            if ($scopeNamespace && preg_match('/^[A-Z][A-Za-z0-9_]*(\\\\[A-Z][A-Za-z0-9_]*)*$/', $scopeNamespace)) {
                $checks[] = ['PASS', 'scope_class_namespace', $scopeNamespace];
            } else {
                $checks[] = ['FAIL', 'scope_class_namespace', "namespace inválido: {$scopeNamespace}"];
                $falhou = true;
            }
        }

        $apiKeyEnv = $config['llm']['api_key_env'] ?? 'OPENROUTER_API_KEY';
        if (env($apiKeyEnv)) {
            $checks[] = ['PASS', "env:{$apiKeyEnv}", 'definida'];
        } else {
            $checks[] = ['WARN', "env:{$apiKeyEnv}", 'não definida — driftguard:analyze vai falhar até configurar'];
        }

        if (($config['llm']['driver'] ?? 'openrouter') === 'cli_harness') {
            foreach ($this->checksHarness($config['llm'] ?? []) as $check) {
                $checks[] = $check;
            }
        }

        if ($this->option('json')) {
            $checksChaveados = array_map(fn($c) => ['status' => $c[0], 'item' => $c[1], 'detalhe' => $c[2]], $checks);
            $this->line(json_encode(['ok' => !$falhou, 'checks' => $checksChaveados], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return $falhou ? self::FAILURE : self::SUCCESS;
        }

        $this->table(['Status', 'Item', 'Detalhe'], $checks);

        return $falhou ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Checks do modo `cli_harness` (regra de segurança em camadas — ver docblock de
     * `CliHarnessAnalysisClient`). Nunca falha o exit code (WARN), só alerta ANTES de rodar
     * `analyze` de verdade.
     *
     * @param array $llm config('driftguard.llm')
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private function checksHarness(array $llm): array
    {
        $checks = [];

        $presetNome = $llm['cli_harness_preset'] ?? 'claude';
        $preset     = $llm['cli_harness_presets'][$presetNome] ?? $llm['cli_harness_presets']['claude'] ?? [];
        $harness    = array_merge($preset, $llm['cli_harness'] ?? []);
        $valor      = fn(string $chave, $default) => array_key_exists($chave, $harness) ? $harness[$chave] : $default;

        $readonlyLock = $llm['readonly_lock'] ?? true;

        if ($this->osFamily() === 'Windows') {
            $checks[] = ['WARN', 'llm.readonly_lock', "modo harness ativo, mas o bloqueio de escrita no "
                . "sistema de arquivos não funciona no Windows (chmod não impõe restrição real de "
                . "diretório lá) — desabilitado automaticamente. A defesa durante a exploração fica só "
                . "com --allowedTools da CLI (se o preset '{$presetNome}' tiver) + a denylist de código."];
        } elseif (!$readonlyLock) {
            $checks[] = ['WARN', 'llm.readonly_lock', "está desligado (readonly_lock => false) — a defesa "
                . "durante a exploração do harness fica só com --allowedTools da CLI (se o preset "
                . "'{$presetNome}' tiver) + a denylist de código."];
        }

        if ($valor('tools_flag', null) === null) {
            $checks[] = ['WARN', 'llm.cli_harness_preset', "o preset '{$presetNome}' não tem allowlist de "
                . "tool própria confirmada (tools_flag null) — o readonly_lock (quando disponível) é a "
                . "defesa principal durante a exploração pra esse preset."];
        }

        $allowedBasePath = config('driftguard.allowed_base_path', base_path());
        if ($allowedBasePath === base_path()) {
            $checks[] = ['WARN', 'llm.allowed_base_path', "está apontando pro projeto inteiro (base_path()) "
                . "— pro modo harness, considere estreitar pra só models_path + supporting_paths: travar/"
                . "explorar o projeto inteiro é mais lento e desnecessário."];
        }

        return $checks;
    }

    /** Sobrescrevível em teste pra simular Windows sem depender do SO real rodando a suite. */
    protected function osFamily(): string
    {
        return PHP_OS_FAMILY;
    }
}
