<?php

namespace Saviogodinho2002\Drifguard\Console;

use Illuminate\Console\Command;
use Saviogodinho2002\Drifguard\ModelSyncService;

/**
 * Estabelece um baseline em context.json (last_commit_hash = HEAD atual) SEM chamar o LLM — pra
 * quem só quer marcar "a partir de agora, reanalise o que mudar" sem pagar o custo de rodar
 * drifguard:analyze --force contra todos os models só pra fazer o arquivo existir. Nunca
 * sobrescreve um context.json já existente sem --force, pra não perder pending_questions/
 * scope_hashes já acumulados numa baseline anterior.
 */
class InitCommand extends Command
{
    protected $signature = 'drifguard:init
                            {--force : Sobrescreve context.json já existente}
                            {--json : Saída em JSON em vez de texto}';

    protected $description = 'Estabelece o baseline de context.json (last_commit_hash atual) sem chamar o LLM';

    public function handle(ModelSyncService $service): int
    {
        $json = (bool) $this->option('json');
        $path = $service->storagePath('context.json');

        if (is_file($path) && !$this->option('force')) {
            if ($json) {
                $this->line(json_encode(['status' => 'already_exists', 'path' => $path]));
            } else {
                $this->warn("Já existe {$path} — use --force pra sobrescrever (perde pending_questions/scope_hashes acumulados nele).");
            }
            return self::FAILURE;
        }

        $hash = $service->getCurrentCommitHash();

        $service->writeContext([
            'last_commit_hash'  => $hash,
            'last_analyzed_at'  => null,
            'pending_questions' => [],
        ]);

        if ($json) {
            $this->line(json_encode([
                'status'           => 'initialized',
                'path'             => $path,
                'last_commit_hash' => $hash,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $this->info("✓ Baseline criado em {$path} (last_commit_hash={$hash}).");
        $this->line('A próxima `drifguard:analyze` (sem --force) só reanalisa o que mudar a partir de agora.');

        return self::SUCCESS;
    }
}
