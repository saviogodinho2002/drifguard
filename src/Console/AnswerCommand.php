<?php

namespace Saviogodinho2002\Drifguard\Console;

use Illuminate\Console\Command;
use Saviogodinho2002\Drifguard\ModelSyncService;

/**
 * Responde a última pergunta pendente (`ask_question`) de um model sem precisar editar
 * questions.md à mão — pensado pra um agente de IA rodando o fluxo de forma autônoma. Fecha o modo
 * `rerun`: a próxima `drifguard:analyze` sem --model/--force inclui esse model automaticamente,
 * com a resposta injetada no contexto da nova análise.
 */
class AnswerCommand extends Command
{
    protected $signature = 'drifguard:answer
                            {model : Nome do model com pergunta pendente}
                            {resposta* : Texto da resposta (várias palavras, sem precisar de aspas)}';

    protected $description = 'Responde a última pergunta pendente de um model (alternativa a editar questions.md)';

    public function handle(ModelSyncService $service): int
    {
        $modelo   = $this->argument('model');
        $resposta = trim(implode(' ', (array) $this->argument('resposta')));

        if ($resposta === '') {
            $this->error('Informe o texto da resposta.');
            return self::FAILURE;
        }

        $resultado = $service->answerQuestion($modelo, $resposta);

        if (!$resultado['found']) {
            $this->warn("Nenhuma pergunta pendente (não respondida) encontrada para '{$modelo}'.");
            return self::FAILURE;
        }

        $this->info("✓ Resposta registrada para '{$modelo}': {$resultado['question']}");
        $this->line('Rode `php artisan drifguard:analyze` pra reanalisar com essa resposta incorporada.');

        return self::SUCCESS;
    }
}
