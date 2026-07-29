<?php

namespace Saviogodinho2002\DriftGuard\Console;

use Illuminate\Console\Command;
use Saviogodinho2002\DriftGuard\ModelSyncService;

/**
 * Introspecção (regra E): pra cada model, mostra se ContextDocsResolver acha algum doc de negócio
 * (explícito ou por convenção) e qual path — pra debugar convenção de nome silenciosa sem rodar
 * uma análise de verdade.
 */
class ContextListCommand extends Command
{
    protected $signature = 'driftguard:context:list
                            {--model=* : Restringe a model(s) específico(s)}
                            {--json : Saída em JSON em vez de tabela}';

    protected $description = 'Mostra qual documento de contexto de negócio seria usado por model';

    public function handle(ModelSyncService $service): int
    {
        $models = (array) $this->option('model');
        if (empty($models)) {
            $models = $service->allModelNames();
        }

        $linhas = array_map(function (string $modelo) use ($service) {
            $doc = $service->contextDocFor($modelo);
            return ['model' => $modelo, 'found' => $doc !== null, 'path' => $doc['path'] ?? null];
        }, $models);

        if ($this->option('json')) {
            $this->line(json_encode($linhas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $this->table(
            ['Model', 'Doc encontrado?', 'Path'],
            array_map(fn($l) => [$l['model'], $l['found'] ? 'sim' : 'não', $l['path'] ?? '—'], $linhas)
        );

        return self::SUCCESS;
    }
}
