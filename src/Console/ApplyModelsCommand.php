<?php

namespace Saviogodinho2002\Drifguard\Console;

use Illuminate\Console\Command;
use Saviogodinho2002\Drifguard\ModelSyncService;

class ApplyModelsCommand extends Command
{
    protected $signature = 'drifguard:apply
                            {--dry-run : Mostra o diff sem aplicar}
                            {--force : Aplica sem pedir confirmação}
                            {--json : Saída em JSON em vez de tabela (exige --force junto, a menos que --dry-run)}';

    protected $description = 'Aplica a proposta gerada por drifguard:analyze no catálogo de saída';

    public function handle(ModelSyncService $service): int
    {
        $json    = (bool) $this->option('json');
        $dryRun  = (bool) $this->option('dry-run');
        $force   = (bool) $this->option('force');

        $proposal = $service->readProposal();
        if (empty($proposal)) {
            if ($json) {
                $this->line(json_encode(['status' => 'no_proposal']));
            } else {
                $this->info('Nenhuma proposta pendente. Rode drifguard:analyze primeiro.');
            }
            return self::SUCCESS;
        }

        $current = $service->readOutputConfig();
        $diff    = $service->buildDiff($current, $proposal);

        if (empty($diff)) {
            if ($json) {
                $this->line(json_encode(['status' => 'no_changes']));
            } else {
                $this->info('Proposta não introduz nenhuma mudança.');
            }
            return self::SUCCESS;
        }

        if (!$json) {
            $this->table(['Model', 'Campo', 'Tipo', 'Detalhe'], array_map(
                fn($linha) => [$linha['model'], $linha['campo'], $linha['tipo'], $linha['detalhe']],
                $diff
            ));
        }

        if ($dryRun) {
            if ($json) {
                $this->line(json_encode(['status' => 'dry_run', 'diff' => $diff], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $this->info('(dry-run — nada foi aplicado)');
            }
            return self::SUCCESS;
        }

        // --json sem --force não aplica silenciosamente: uma execução headless que só queria
        // inspecionar o diff (esqueceu --dry-run) não deve, por acidente, gravar mudança nenhuma.
        if ($json && !$force) {
            $this->line(json_encode([
                'status'  => 'confirmation_required',
                'message' => 'Rode de novo com --force (junto de --json) pra aplicar sem prompt interativo, ou --dry-run pra só inspecionar.',
                'diff'    => $diff,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::FAILURE;
        }

        if (!$json && !$force && !$this->confirm('Aplicar essas mudanças?', true)) {
            $this->warn('Cancelado.');
            return self::SUCCESS;
        }

        $resultado = $service->apply($current, $proposal);
        $service->clearProposal();

        if ($json) {
            $this->line(json_encode([
                'status'        => 'applied',
                'diff'          => $diff,
                'scope_results' => $resultado['scopeResults'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

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

        $this->info('✓ Aplicado.');

        return self::SUCCESS;
    }
}
