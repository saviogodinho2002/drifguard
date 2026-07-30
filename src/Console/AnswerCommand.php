<?php

namespace Saviogodinho2002\DriftGuard\Console;

use Illuminate\Console\Command;
use Saviogodinho2002\DriftGuard\ModelSyncService;

/**
 * Responde a última pergunta pendente (`ask_question`) de um model sem precisar editar
 * questions.md à mão — pensado pra um agente de IA rodando o fluxo de forma autônoma. Fecha o modo
 * `rerun`: a próxima `driftguard:analyze` sem --model/--force inclui esse model automaticamente,
 * com a resposta injetada no contexto da nova análise.
 */
class AnswerCommand extends Command
{
    protected $signature = 'driftguard:answer
                            {model : Nome do model com pergunta pendente}
                            {resposta* : Texto da resposta (várias palavras, sem precisar de aspas)}
                            {--json : Saída em JSON em vez de texto}';

    protected $description = 'Responde a última pergunta pendente de um model (alternativa a editar questions.md)';

    public function handle(ModelSyncService $service): int
    {
        $json     = (bool) $this->option('json');
        $modelo   = $this->argument('model');
        $resposta = trim(implode(' ', (array) $this->argument('resposta')));

        if ($resposta === '') {
            if ($json) {
                $this->line(json_encode(['status' => 'missing_answer_text']));
            } else {
                $this->error('Informe o texto da resposta.');
            }
            return self::FAILURE;
        }

        $resultado = $service->answerQuestion($modelo, $resposta);

        if (!$resultado['found']) {
            if ($json) {
                $this->line(json_encode(['status' => 'not_found', 'model' => $modelo]));
            } else {
                $this->warn("Nenhuma pergunta pendente (não respondida) encontrada para '{$modelo}'.");
            }
            return self::FAILURE;
        }

        if ($json) {
            $this->line(json_encode([
                'status'   => 'answered',
                'model'    => $modelo,
                'question' => $resultado['question'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $this->info("✓ Resposta registrada para '{$modelo}': {$resultado['question']}");
        $this->line('Rode `php artisan driftguard:analyze` pra reanalisar com essa resposta incorporada.');

        return self::SUCCESS;
    }
}
