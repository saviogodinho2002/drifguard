<?php

namespace Saviogodinho2002\Drifguard\Console;

use Illuminate\Console\Command;
use Saviogodinho2002\Drifguard\ModelSyncService;

class AnalyzeModelsCommand extends Command
{
    protected $signature = 'drifguard:analyze
                            {--force : Reanalisar todos os models independente de git diff}
                            {--model=* : Limitar análise a model(s) específico(s)}';

    protected $description = 'Usa um LLM (configurável) pra atualizar o catálogo de models a partir das mudanças no código';

    public function handle(ModelSyncService $service): int
    {
        $force  = (bool) $this->option('force');
        $models = (array) $this->option('model');

        $this->info('Resolvendo estado da análise...');
        $state = $service->resolveRunState($force, $models);

        $modelsList = $state['models'];
        $mode       = $state['mode'];

        $missing = $service->findMissingModels();
        if (!empty($missing)) {
            $this->line('<comment>Models sem entrada no catálogo (incluídos na análise): ' . implode(', ', $missing) . '</comment>');
        }

        if (empty($modelsList)) {
            $this->info('Nenhuma mudança detectada desde a última análise.');
            $this->line('Use --force para reanalisar todos os models.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(['Modo', 'Models a analisar'], [[$mode, implode(', ', $modelsList)]]);
        $this->newLine();

        $bar = $this->output->createProgressBar(count($modelsList));
        $bar->start();

        $result = $service->runAnalysis($modelsList, function () use ($bar) {
            $bar->advance();
        });

        $bar->finish();
        $this->newLine(2);

        $proposals = $result['proposals'];
        $questions = $result['questions'];

        if (empty($proposals) && empty($questions)) {
            $this->warn('Nenhuma proposta retornada. Verifique a configuração do cliente de análise (chave de API etc).');
            return self::FAILURE;
        }

        $ctx = $service->readContext();
        $ctx['last_commit_hash'] = $service->getCurrentCommitHash();
        $ctx['last_analyzed_at'] = date(DATE_ATOM);
        $service->writeContext($ctx);

        if (!empty($proposals)) {
            $service->writeProposal($proposals, $ctx);
            $this->info('✓ Proposta salva em: ' . $service->storagePath('proposal.php'));
        }

        if (!empty($questions)) {
            $service->writeQuestions($questions, $ctx);
            $this->warn(count($questions) . ' dúvida(s) pendente(s) em: ' . $service->storagePath('questions.md'));
        } else {
            $this->info('Pronto para aplicar: php artisan drifguard:apply --dry-run');
        }

        return self::SUCCESS;
    }
}
