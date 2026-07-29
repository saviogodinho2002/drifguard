<?php

namespace Saviogodinho2002\Drifguard\Support;

/**
 * Monta o prompt do sistema e o array de tools (formato tool-calling) a partir da mecânica
 * genérica do pacote + config('drifguard.fields') do app-host + regras extras opcionais. Nenhuma
 * regra de negócio de projeto nenhum vive aqui — só a mecânica (prioridade de reflection,
 * disciplina notas-vs-campo-resumo, uso de request_file/ask_question).
 */
class PromptBuilder
{
    /** @param FieldSpec[] $fieldSpecs */
    public function __construct(
        private readonly array $fieldSpecs,
        private readonly ?string $extraPromptRules = null,
    ) {
    }

    public function buildSystemPrompt(): string
    {
        $base = <<<TXT
        Você analisa um model Eloquent e propõe uma entrada de catálogo pra documentá-lo — descrição
        de negócio, notas de regra, e os campos extras definidos abaixo. Regras invioláveis:

        1. Fatos estruturais (tabela, campos, relações) já vêm resolvidos por reflection real sobre o
           código — NUNCA proponha esses campos, eles são sempre calculados, nunca por você.
        2. `notas` é dono da MECÂNICA (condição exata, o porquê) — não repita a mesma explicação em
           outro campo que só deveria resumir. Duplicar a mesma regra em 2 campos custa contexto sem
           ganhar informação.
        3. Nunca invente uma regra de negócio que não consegue confirmar lendo o código ou os
           documentos de contexto fornecidos. Se precisar de mais informação, use `request_file` (pra
           puxar outro arquivo) ou `ask_question` (pra perguntar a um humano) — nunca adivinhe.
        4. Se um documento de contexto de negócio foi fornecido, ele é HUMANO e AUTORITATIVO sobre
           intenção/motivo de negócio — mas mesmo assim nunca sobrescreve fato estrutural vindo de
           reflection (regra 1 sempre vence).
        TXT;

        $regrasDeCampo = $this->regrasPorCampo();
        $partes        = [$base];

        if ($regrasDeCampo !== '') {
            $partes[] = "Instruções por campo:\n{$regrasDeCampo}";
        }

        $regrasScopeClass = $this->regrasScopeClass();
        if ($regrasScopeClass !== '') {
            $partes[] = $regrasScopeClass;
        }

        if ($this->extraPromptRules) {
            $partes[] = $this->extraPromptRules;
        }

        return implode("\n\n", $partes);
    }

    /**
     * Reforço de formato pro tipo scope_class, no nível do PACOTE — não depende do host reescrever
     * isso na própria instrução. Mesmo texto de `FieldSpec::SCOPE_CLASS_FORMAT_CONTRACT`, entregue
     * também aqui (redundância intencional, ver comentário na constante).
     */
    private function regrasScopeClass(): string
    {
        $camposScope = array_filter($this->fieldSpecs, fn($s) => $s->type === FieldSpec::TYPE_SCOPE_CLASS);
        if (empty($camposScope)) {
            return '';
        }

        $nomes = implode(', ', array_map(fn($s) => "`{$s->name}`", $camposScope));

        return "Contrato OBRIGATÓRIO de formato pros campos do tipo scope_class ({$nomes}): "
            . FieldSpec::SCOPE_CLASS_FORMAT_CONTRACT;
    }

    private function regrasPorCampo(): string
    {
        $linhas = [];
        foreach ($this->fieldSpecs as $spec) {
            if ($spec->llmInstructions !== '') {
                $linhas[] = "- `{$spec->name}` ({$spec->type}): {$spec->llmInstructions}";
            }
        }
        return implode("\n", $linhas);
    }

    /** @return array<int, array{type: string, function: array}> */
    public function buildTools(): array
    {
        $properties = [
            'descricao' => ['type' => 'string', 'description' => 'Descrição de negócio do model, 1-3 frases.'],
            'notas'     => ['type' => 'string', 'description' => 'Regras de negócio/mecânica não-óbvias a partir só do nome dos campos.'],
        ];

        foreach ($this->fieldSpecs as $spec) {
            $properties[$spec->name] = $spec->toToolProperty();
        }

        $required = array_values(array_filter(
            array_keys($properties),
            fn($nome) => ($this->specFor($nome)?->required ?? false)
        ));

        return [
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'propose_update',
                    'description' => 'Propõe a entrada de catálogo pro model analisado.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => $properties,
                        'required'   => $required,
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'request_file',
                    'description' => 'Pede o conteúdo de outro arquivo do código pra ter mais contexto antes de propor.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => ['path' => ['type' => 'string', 'description' => 'Caminho relativo do arquivo desejado.']],
                        'required'   => ['path'],
                    ],
                ],
            ],
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'ask_question',
                    'description' => 'Defere a decisão a um humano quando não dá pra confirmar algo com segurança pelo código disponível.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => ['question' => ['type' => 'string']],
                        'required'   => ['question'],
                    ],
                ],
            ],
        ];
    }

    private function specFor(string $nome): ?FieldSpec
    {
        foreach ($this->fieldSpecs as $spec) {
            if ($spec->name === $nome) {
                return $spec;
            }
        }
        return null;
    }

    /**
     * @param array<string, string> $snippets caminho => conteúdo
     * @param array{path: string, content: string}|null $contextDoc
     * @param array{tabela: string, campos: string, relacoes: string} $reflectedMetadata
     * @param array{question: string, answer: string}|null $respostaAnterior Pergunta feita numa
     *        rodada anterior (`ask_question`) e já respondida por um humano via `drifguard:answer` —
     *        entra como contexto humano/autoritativo sobre intenção, igual $contextDoc, nunca
     *        sobrescreve fato estrutural.
     * @return array<int, array{role: string, content: string}>
     */
    public function buildMessages(string $modelo, array $snippets, ?array $contextDoc, array $reflectedMetadata, ?array $respostaAnterior = null): array
    {
        $partesUsuario = [
            "Model: {$modelo}",
            "Fatos estruturais (reflection, autoritativo): tabela={$reflectedMetadata['tabela']}; campos={$reflectedMetadata['campos']}; relacoes={$reflectedMetadata['relacoes']}",
        ];

        foreach ($snippets as $path => $conteudo) {
            $partesUsuario[] = "--- {$path} ---\n{$conteudo}";
        }

        if ($contextDoc !== null) {
            $partesUsuario[] = "--- contexto de negócio ({$contextDoc['path']}) ---\n{$contextDoc['content']}";
        }

        if ($respostaAnterior !== null) {
            $partesUsuario[] = "--- pergunta anterior, já respondida por um humano ---\n"
                . "Pergunta: {$respostaAnterior['question']}\nResposta: {$respostaAnterior['answer']}";
        }

        return [
            ['role' => 'system', 'content' => $this->buildSystemPrompt()],
            ['role' => 'user', 'content' => implode("\n\n", $partesUsuario)],
        ];
    }
}
