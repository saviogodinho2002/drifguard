<?php

namespace Saviogodinho2002\Drifguard\Console;

use Illuminate\Console\Command;
use Saviogodinho2002\Drifguard\ModelSyncService;

class ApplyModelsCommand extends Command
{
    protected $signature = 'drifguard:apply
                            {--dry-run : Mostra o diff sem aplicar}
                            {--force : Aplica sem pedir confirmação}';

    protected $description = 'Aplica a proposta gerada por drifguard:analyze no catálogo de saída';

    public function handle(ModelSyncService $service): int
    {
        $proposal = $service->readProposal();
        if (empty($proposal)) {
            $this->info('Nenhuma proposta pendente. Rode drifguard:analyze primeiro.');
            return self::SUCCESS;
        }

        $current = $service->readOutputConfig();
        $diff    = $service->buildDiff($current, $proposal);

        if (empty($diff)) {
            $this->info('Proposta não introduz nenhuma mudança.');
            return self::SUCCESS;
        }

        $this->table(['Model', 'Campo', 'Tipo', 'Detalhe'], array_map(
            fn($linha) => [$linha['model'], $linha['campo'], $linha['tipo'], $linha['detalhe']],
            $diff
        ));

        if ($this->option('dry-run')) {
            $this->info('(dry-run — nada foi aplicado)');
            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm('Aplicar essas mudanças?', true)) {
            $this->warn('Cancelado.');
            return self::SUCCESS;
        }

        $resultado = $service->apply($current, $proposal);

        foreach ($resultado['scopeResults'] as $sr) {
            $rotulo = match ($sr['status']) {
                'written'             => "<info>✓ classe de escopo gerada</info>",
                'skipped_manual_edit' => "<comment>⚠ pulado (editado manualmente)</comment>",
                'syntax_error'        => "<error>✗ erro de sintaxe, não gravado</error>",
                default               => $sr['status'],
            };
            $this->line("{$sr['model']}.{$sr['field']}: {$rotulo} — {$sr['path']}");
            if ($sr['message']) {
                $this->line("    {$sr['message']}");
            }
        }

        $service->clearProposal();
        $this->info('✓ Aplicado.');

        return self::SUCCESS;
    }
}
