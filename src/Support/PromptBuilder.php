<?php

namespace Saviogodinho2002\DriftGuard\Support;

/**
 * Monta o prompt do sistema e o array de tools (formato tool-calling) a partir da mecânica
 * genérica do pacote + config('driftguard.fields') do app-host + regras extras opcionais. Nenhuma
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
        4. Se um documento de contexto de negócio OU uma resposta humana anterior (via ask_question)
           foi fornecido, é HUMANO e AUTORITATIVO sobre intenção/motivo de negócio — mas mesmo assim
           nunca sobrescreve fato estrutural vindo de reflection (regra 1 sempre vence).
        5. Se essa informação humana parecer CONTRADIZER o que você observa lendo o código (não fato
           estrutural — isso a regra 1 já resolve — mas comportamento/mecânica aparente), NÃO
           resolva a divergência sozinho nem escolha um lado em silêncio: use ask_question citando
           as duas versões (o que a fonte humana diz vs. o que o código parece indicar) e deixe a
           decisão pra quem revisar.
        6. Se você observar 2+ arquivos do PRÓPRIO código implementando a mesma regra de negócio de
           formas que parecem DIVERGIR entre si (ex: pontos de entrada diferentes filtrando/validando
           de jeitos diferentes) — não é caso da regra 1 (fato estrutural) nem da regra 5 (fonte
           humana vs. código) — NÃO escolha a versão que parecer mais "correta" ou "limpa" em
           silêncio: use ask_question citando os arquivos e o comportamento observado em cada um, e
           deixe pra quem revisar decidir qual (ou se nenhuma) está certa.
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

        $partes[] = $this->regrasRequestMethod();

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

    /**
     * Sempre incluída (ao contrário de `regrasScopeClass()`) — qualquer model pode ter conteúdo
     * descartado por orçamento (`ModelDiscovery::packWithinBudget()`), não só quem tem campo
     * scope_class configurado. Sem isso, a IA não sabe que `request_method` existe
     * preferencialmente a `request_file`, nem que pode/deve pedir vários métodos numa só chamada.
     */
    private function regrasRequestMethod(): string
    {
        return "Se um snippet vier com aviso \"método(s) descartado(s) por orçamento\", os NOMES "
            . "exatos estão listados no próprio aviso — use request_method com esses nomes (nunca "
            . "invente nome de método). Se precisar de mais de 1 método, peça todos numa ÚNICA "
            . "chamada de request_method (campo `requests` aceita lista) em vez de uma chamada por "
            . "método — o número de rodadas de análise é limitado. Prefira request_method a "
            . "request_file quando só faltam métodos específicos, não o arquivo inteiro.";
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
                    'name'        => 'request_method',
                    'description' => 'Pede o corpo de um ou mais métodos específicos que não vieram '
                        . 'completos no contexto (descartados por orçamento — os nomes exatos '
                        . 'aparecem no aviso do snippet). Pode pedir VÁRIOS métodos numa única '
                        . 'chamada. Use em vez de request_file quando só faltam métodos específicos, '
                        . 'não o arquivo todo.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'requests' => [
                                'type'        => 'array',
                                'description' => 'Lista de métodos a pedir, cada um com o path do arquivo e o nome exato do método.',
                                'items'       => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'path'   => ['type' => 'string', 'description' => 'Caminho do arquivo (o mesmo do cabeçalho do snippet).'],
                                        'method' => ['type' => 'string', 'description' => 'Nome exato do método desejado.'],
                                    ],
                                    'required'   => ['path', 'method'],
                                ],
                            ],
                        ],
                        'required'   => ['requests'],
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
     *        rodada anterior (`ask_question`) e já respondida por um humano via `driftguard:answer` —
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
