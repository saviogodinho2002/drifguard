<?php

namespace Saviogodinho2002\Drifguard\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Saviogodinho2002\Drifguard\Support\FieldSpec;

/**
 * Valida config('drifguard.*') sem chamar o LLM (regra A) — pra pegar campo malformado, path
 * inexistente/não-gravável ou chave de API ausente ANTES de rodar drifguard:analyze de verdade.
 */
class DoctorCommand extends Command
{
    protected $signature = 'drifguard:doctor';

    protected $description = 'Valida a config do drifguard (fields, paths, chave de API) sem chamar o LLM';

    public function handle(): int
    {
        $config  = config('drifguard', []);
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
            $checks[] = ['WARN', "env:{$apiKeyEnv}", 'não definida — drifguard:analyze vai falhar até configurar'];
        }

        $this->table(['Status', 'Item', 'Detalhe'], $checks);

        return $falhou ? self::FAILURE : self::SUCCESS;
    }
}
