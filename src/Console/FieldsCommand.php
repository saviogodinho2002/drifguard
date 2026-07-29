<?php

namespace Saviogodinho2002\Drifguard\Console;

use Illuminate\Console\Command;
use Saviogodinho2002\Drifguard\Support\FieldSpec;

/** Introspecção (regra E): lista os FieldSpec ativos sem precisar ler config/drifguard.php na unha. */
class FieldsCommand extends Command
{
    protected $signature = 'drifguard:fields {--json : Saída em JSON em vez de tabela}';

    protected $description = 'Lista os campos extras (FieldSpec) configurados no catálogo';

    public function handle(): int
    {
        $fieldSpecs = array_map(
            fn($spec) => $spec instanceof FieldSpec ? $spec : FieldSpec::fromArray($spec),
            config('drifguard.fields', [])
        );

        $linhas = array_map(fn(FieldSpec $s) => [
            'name'             => $s->name,
            'type'             => $s->type,
            'required'         => $s->required,
            'enum_values'      => $s->enumValues,
            'llm_instructions' => $s->llmInstructions,
        ], $fieldSpecs);

        if ($this->option('json')) {
            $this->line(json_encode($linhas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        if (empty($linhas)) {
            $this->info("Nenhum campo extra configurado em config('drifguard.fields').");
            return self::SUCCESS;
        }

        $this->table(
            ['Nome', 'Tipo', 'Obrigatório', 'Enum', 'Instrução (preview)'],
            array_map(fn($l) => [
                $l['name'],
                $l['type'],
                $l['required'] ? 'sim' : 'não',
                implode(',', $l['enum_values']),
                mb_substr($l['llm_instructions'], 0, 60),
            ], $linhas)
        );

        return self::SUCCESS;
    }
}
