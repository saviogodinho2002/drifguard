<?php

namespace Saviogodinho2002\Drifguard;

use Illuminate\Support\ServiceProvider;
use Saviogodinho2002\Drifguard\Clients\OpenRouterAnalysisClient;
use Saviogodinho2002\Drifguard\Console\AnalyzeModelsCommand;
use Saviogodinho2002\Drifguard\Console\AnswerCommand;
use Saviogodinho2002\Drifguard\Console\ApplyModelsCommand;
use Saviogodinho2002\Drifguard\Console\ContextListCommand;
use Saviogodinho2002\Drifguard\Console\DoctorCommand;
use Saviogodinho2002\Drifguard\Console\FieldsCommand;
use Saviogodinho2002\Drifguard\Console\InitCommand;
use Saviogodinho2002\Drifguard\Contracts\AnalysisClient;
use Saviogodinho2002\Drifguard\Support\ConfigWriter;
use Saviogodinho2002\Drifguard\Support\ContextDocsResolver;
use Saviogodinho2002\Drifguard\Support\FieldSpec;
use Saviogodinho2002\Drifguard\Support\ModelDiscovery;
use Saviogodinho2002\Drifguard\Support\ModelReflector;
use Saviogodinho2002\Drifguard\Support\ScopeClassWriter;

class DrifguardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/drifguard.php', 'drifguard');

        // Binding padrão do cliente de análise — um app-host pode sobrescrever isso no PRÓPRIO
        // ServiceProvider (bind depois do nosso, ou via config de prioridade de provider) se quiser
        // trocar de implementação sem tocar neste pacote.
        $this->app->bind(AnalysisClient::class, function ($app) {
            $config = $app['config']->get('drifguard.llm', []);
            $apiKey = env($config['api_key_env'] ?? 'OPENROUTER_API_KEY', '');

            return new OpenRouterAnalysisClient(
                apiKey: $apiKey,
                model: $config['model'] ?? 'anthropic/claude-sonnet-4-6',
                maxTokens: $config['max_tokens'] ?? 8000,
                timeoutSeconds: $config['timeout'] ?? 300,
            );
        });

        $this->app->singleton(ModelSyncService::class, function ($app) {
            $config = $app['config']->get('drifguard', []);

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
            // lista — dá pra misturar os dois estilos em config('drifguard.fields').
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
                __DIR__ . '/../config/drifguard.php' => config_path('drifguard.php'),
            ], 'drifguard-config');
        }
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
