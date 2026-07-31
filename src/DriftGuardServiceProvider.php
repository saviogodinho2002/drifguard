<?php

namespace Saviogodinho2002\DriftGuard;

use Illuminate\Support\ServiceProvider;
use Saviogodinho2002\DriftGuard\Clients\CliHarnessAnalysisClient;
use Saviogodinho2002\DriftGuard\Clients\OpenRouterAnalysisClient;
use Saviogodinho2002\DriftGuard\Console\AnalyzeModelsCommand;
use Saviogodinho2002\DriftGuard\Console\AnswerCommand;
use Saviogodinho2002\DriftGuard\Console\ApplyModelsCommand;
use Saviogodinho2002\DriftGuard\Console\ContextListCommand;
use Saviogodinho2002\DriftGuard\Console\DoctorCommand;
use Saviogodinho2002\DriftGuard\Console\FieldsCommand;
use Saviogodinho2002\DriftGuard\Console\InitCommand;
use Saviogodinho2002\DriftGuard\Contracts\AnalysisClient;
use Saviogodinho2002\DriftGuard\Support\ConfigWriter;
use Saviogodinho2002\DriftGuard\Support\ContextDocsResolver;
use Saviogodinho2002\DriftGuard\Support\FieldSpec;
use Saviogodinho2002\DriftGuard\Support\ModelDiscovery;
use Saviogodinho2002\DriftGuard\Support\ModelReflector;
use Saviogodinho2002\DriftGuard\Support\ScopeClassWriter;

class DriftGuardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/driftguard.php', 'driftguard');

        // Binding padrão do cliente de análise — um app-host pode sobrescrever isso no PRÓPRIO
        // ServiceProvider (bind depois do nosso, ou via config de prioridade de provider) se quiser
        // trocar de implementação sem tocar neste pacote.
        $this->app->bind(AnalysisClient::class, function ($app) {
            $config = $app['config']->get('driftguard.llm', []);

            if (($config['driver'] ?? 'openrouter') === 'cli_harness') {
                $allowedBasePath = $app['config']->get('driftguard.allowed_base_path', base_path());
                return $this->makeCliHarnessClient($config, $allowedBasePath);
            }

            $apiKey = env($config['api_key_env'] ?? 'OPENROUTER_API_KEY', '');

            return new OpenRouterAnalysisClient(
                apiKey: $apiKey,
                model: $config['model'] ?? 'anthropic/claude-sonnet-4-6',
                maxTokens: $config['max_tokens'] ?? 8000,
                timeoutSeconds: $config['timeout'] ?? 300,
            );
        });

        $this->app->singleton(ModelSyncService::class, function ($app) {
            $config = $app['config']->get('driftguard', []);

            $discovery = new ModelDiscovery(
                modelsPath: $config['models_path'],
                modelNamespace: $config['model_namespace'],
                supportingPaths: $config['supporting_paths'] ?? [],
            );

            $reflector = new ModelReflector(modelNamespace: $config['model_namespace']);

            $contextDocsConfig = $config['context_docs'] ?? [];
            $contextDocs = new ContextDocsResolver(
                basePath: $contextDocsConfig['base_path'] ?? base_path(),
                explicitMap: $contextDocsConfig['map'] ?? [],
                conventionPath: $contextDocsConfig['convention_path'] ?? null,
            );

            // Aceita FieldSpec já construído (factories fluentes) OU array cru (fromArray) na mesma
            // lista — dá pra misturar os dois estilos em config('driftguard.fields').
            $fieldSpecs = array_map(
                fn($spec) => $spec instanceof FieldSpec ? $spec : FieldSpec::fromArray($spec),
                $config['fields'] ?? []
            );

            $temScopeClass = !empty(array_filter($fieldSpecs, fn($s) => $s->type === FieldSpec::TYPE_SCOPE_CLASS));
            $scopeClassWriter = $temScopeClass
                ? new ScopeClassWriter(
                    outputPath: $config['scope_class_path'],
                    namespace: $config['scope_class_namespace'],
                )
                : null;

            $extraPromptRules = $this->resolveExtraPromptRules($config['extra_prompt_rules'] ?? null);

            return new ModelSyncService(
                client: $app->make(AnalysisClient::class),
                discovery: $discovery,
                reflector: $reflector,
                contextDocs: $contextDocs,
                configWriter: new ConfigWriter(),
                scopeClassWriter: $scopeClassWriter,
                fieldSpecs: $fieldSpecs,
                extraPromptRules: $extraPromptRules,
                outputConfigPath: $config['output_path'],
                storagePath: $config['storage_path'],
                maxSnippetChars: $config['max_snippet_chars'] ?? 6000,
                allowedBasePath: $config['allowed_base_path'] ?? base_path(),
                maxTotalSnippetChars: $config['max_total_snippet_chars'] ?? 60000,
                maxSupportingFiles: $config['max_supporting_files'] ?? 5,
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                AnalyzeModelsCommand::class,
                ApplyModelsCommand::class,
                DoctorCommand::class,
                InitCommand::class,
                AnswerCommand::class,
                FieldsCommand::class,
                ContextListCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/driftguard.php' => config_path('driftguard.php'),
            ], 'driftguard-config');
        }
    }

    /**
     * Resolve o preset escolhido em 'cli_harness_preset' (claude/gemini/opencode — só o de claude
     * foi validado ao vivo, os outros seguem documentação/issues públicas do GitHub, ver comentário
     * em config/driftguard.php), com override pontual de qualquer chave via 'cli_harness'.
     */
    private function makeCliHarnessClient(array $config, string $allowedBasePath): CliHarnessAnalysisClient
    {
        $presetNome = $config['cli_harness_preset'] ?? 'claude';
        $preset     = $config['cli_harness_presets'][$presetNome] ?? $config['cli_harness_presets']['claude'] ?? [];
        $harness    = array_merge($preset, $config['cli_harness'] ?? []);

        // array_key_exists, NUNCA ?? — um preset pode setar uma chave como null de propósito (ex:
        // gemini.dir_flag => null, porque não há flag de restrição de diretório confirmada na doc
        // pra essa CLI). ?? trataria "existe e é null" igual a "não existe" e reintroduziria o
        // default errado (ex: --add-dir do Claude Code CLI virando argumento de uma chamada gemini).
        $valor = fn(string $chave, $default) => array_key_exists($chave, $harness) ? $harness[$chave] : $default;

        return new CliHarnessAnalysisClient(
            command: $valor('command', 'claude'),
            extraArgs: $valor('extra_args', ['--output-format', 'json']),
            responseFormat: $valor('response_format', 'single_json'),
            resultField: $valor('result_field', 'result'),
            costField: $valor('cost_field', 'total_cost_usd'),
            promptTokensField: $valor('prompt_tokens_field', null),
            completionTokensField: $valor('completion_tokens_field', null),
            timeoutSeconds: $valor('timeout', $config['timeout'] ?? 300),
            allowedBasePath: $allowedBasePath,
            harnessTools: $valor('harness_tools', ['Read', 'Grep', 'Glob']),
            dirFlag: $valor('dir_flag', '--add-dir'),
            toolsFlag: $valor('tools_flag', '--allowedTools'),
            readonlyLock: $config['readonly_lock'] ?? true,
        );
    }

    private function resolveExtraPromptRules(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_file($value)) {
            return file_get_contents($value) ?: null;
        }
        return $value;
    }
}
