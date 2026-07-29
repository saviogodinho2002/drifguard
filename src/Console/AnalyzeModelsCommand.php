<?php

namespace Saviogodinho2002\DriftGuard\Console;

use Illuminate\Console\Command;
use Saviogodinho2002\DriftGuard\ModelSyncService;

class AnalyzeModelsCommand extends Command
{
    protected $signature = 'driftguard:analyze
                            {--force : Reanalisar todos os models independente de git diff}
                            {--model=* : Limitar análise a model(s) específico(s)}
                            {--dry-run : Mostra modo/models/prévia sem chamar o LLM}
                            {--json : Saída em JSON em vez de tabela/progresso (implica saída não-interativa)}';

    protected $description = 'Usa um LLM (configurável) pra atualizar o catálogo de models a partir das mudanças no código';

    public function handle(ModelSyncService $service): int
    {
        $force  = (bool) $this->option('force');
        $models = (array) $this->option('model');
        $json   = (bool) $this->option('json');

        $state      = $service->resolveRunState($force, $models);
        $modelsList = $state['models'];
        $mode       = $state['mode'];
        $missing    = $service->findMissingModels();

        if ($this->option('dry-run')) {
            $preview = $service->previewFor($modelsList);

            if ($json) {
                $this->line(json_encode([
                    'mode'    => $mode,
                    'models'  => $preview,
                    'missing' => $missing,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                return self::SUCCESS;
            }

            $this->info("Modo: {$mode}");
            if (!empty($missing)) {
                $this->line('<comment>Models sem entrada no catálogo: ' . implode(', ', $missing) . '</comment>');
            }
            if (empty($preview)) {
                $this->info('Nenhum model seria analisado (nada mudou desde a última rodada).');
                return self::SUCCESS;
            }
            $this->table(
                ['Model', 'Arquivos de apoio', 'Doc de contexto'],
                array_map(fn($p) => [$p['model'], $p['supporting_files'], $p['context_doc'] ?? '—'], $preview)
            );
            $this->info('(dry-run — nenhuma chamada ao LLM foi feita)');
            return self::SUCCESS;
        }

        if (!$json) {
            $this->info('Resolvendo estado da análise...');
        }

        if (!empty($missing) && !$json) {
            $this->line('<comment>Models sem entrada no catálogo (incluídos na análise): ' . implode(', ', $missing) . '</comment>');
        }

        if (empty($modelsList)) {
            if ($json) {
                $this->line(json_encode(['mode' => $mode, 'models' => [], 'proposals' => [], 'questions' => []]));
                return self::SUCCESS;
            }
            $this->info('Nenhuma mudança detectada desde a última análise.');
            $this->line('Use --force para reanalisar todos os models.');
            return self::SUCCESS;
        }

        if (!$json) {
            $this->newLine();
            $this->table(['Modo', 'Models a analisar'], [[$mode, implode(', ', $modelsList)]]);
            $this->newLine();
        }

        $bar = $json ? null : $this->output->createProgressBar(count($modelsList));
        $bar?->start();

        $result = $service->runAnalysis($modelsList, function () use ($bar) {
            $bar?->advance();
        });

        $bar?->finish();
        if (!$json) {
            $this->newLine(2);
        }

        $proposals = $result['proposals'];
        $questions = $result['questions'];

        if (empty($proposals) && empty($questions)) {
            if ($json) {
                $this->line(json_encode(['mode' => $mode, 'models' => $modelsList, 'proposals' => [], 'questions' => [], 'error' => 'nenhuma proposta retornada']));
            } else {
                $this->warn('Nenhuma proposta retornada. Verifique a configuração do cliente de análise (chave de API etc).');
            }
            return self::FAILURE;
        }

        $ctx = $service->readContext();
        $ctx['last_commit_hash'] = $service->getCurrentCommitHash();
        $ctx['last_analyzed_at'] = date(DATE_ATOM);
        $service->writeContext($ctx);

        if (!empty($proposals)) {
            $service->writeProposal($proposals, $ctx);
        }
        if (!empty($questions)) {
            $service->writeQuestions($questions, $ctx);
        }

        if ($json) {
            $this->line(json_encode([
                'mode'      => $mode,
                'models'    => $modelsList,
                'proposals' => $proposals,
                'questions' => $questions,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        if (!empty($proposals)) {
            $this->info('✓ Proposta salva em: ' . $service->storagePath('proposal.php'));
        }
        if (!empty($questions)) {
            $this->warn(count($questions) . ' dúvida(s) pendente(s) em: ' . $service->storagePath('questions.md'));
            $this->line('Responda via `php artisan driftguard:answer {model} {resposta}` ou editando o arquivo acima.');
        } else {
            $this->info('Pronto para aplicar: php artisan driftguard:apply --dry-run');
        }

        return self::SUCCESS;
    }
}
