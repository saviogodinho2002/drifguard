<?php

namespace Saviogodinho2002\DriftGuard\Support;

/**
 * Faz o merge de uma proposta da IA contra o config atual, e reescreve o arquivo de saída
 * (config/models.php ou o path que o app-host configurar). Regras de preservação (nenhuma perda
 * silenciosa de curadoria manual):
 *
 * 1. Campo que a IA omitiu na proposta: mantém o valor atual (backfill).
 * 2. Campo presente no config atual mas SEM FieldSpec correspondente (host removeu/renomeou o
 *    campo da spec, ou é um campo que nunca fez parte da spec) — preservado verbatim na reescrita,
 *    nunca descartado. Achado de revisão do plano: sem isso, trocar a spec apagaria dado de todo
 *    model silenciosamente na próxima aplicação.
 */
class ConfigWriter
{
    private const CAMPOS_BASE = ['descricao', 'tabela', 'campos', 'relacoes', 'notas'];

    /**
     * @param array<string, array> $current
     * @param array<string, array> $proposal
     * @return array<string, array> merge com proposta vencendo, current como fallback de campo vazio
     */
    public function mergeProposal(array $current, array $proposal): array
    {
        $merged = $current;

        foreach ($proposal as $modelo => $entradaProposta) {
            $entradaAtual = $current[$modelo] ?? [];
            $mesclada     = $entradaAtual; // começa com tudo que já existe (preserva campos órfãos automaticamente)

            foreach ($entradaProposta as $campo => $valor) {
                if ($valor === null || $valor === '' || $valor === []) {
                    continue; // IA omitiu — mantém o que já estava (não sobrescreve com vazio)
                }
                $mesclada[$campo] = $valor;
            }

            $merged[$modelo] = $mesclada;
        }

        return $merged;
    }

    /**
     * @param array<string, array> $merged
     * @param FieldSpec[] $fieldSpecs
     */
    public function write(string $path, array $merged, array $fieldSpecs): void
    {
        $header = $this->extractHeader($path);
        $corpo  = $this->serializeAll($merged, $fieldSpecs);

        file_put_contents($path, $header . "return [\n{$corpo}];\n");
    }

    private function extractHeader(string $path): string
    {
        if (!is_file($path)) {
            return "<?php\n\n";
        }
        $conteudo = file_get_contents($path) ?: '';
        if (preg_match('/^(.*?)return\s*\[/s', $conteudo, $m)) {
            return $m[1];
        }
        return "<?php\n\n";
    }

    /** @param array<string, array> $entradas @param FieldSpec[] $fieldSpecs */
    private function serializeAll(array $entradas, array $fieldSpecs): string
    {
        $blocos = [];
        foreach ($entradas as $modelo => $entrada) {
            $blocos[] = $this->serializeEntry($modelo, $entrada, $fieldSpecs);
        }
        return implode("\n", $blocos);
    }

    private function serializeEntry(string $modelo, array $entrada, array $fieldSpecs): string
    {
        $ordemCampos = self::CAMPOS_BASE;
        foreach ($fieldSpecs as $spec) {
            $ordemCampos[] = $spec->name;
        }

        // Campos órfãos: existem na entrada mas não estão nem na base nem na spec atual —
        // preservados no FINAL do bloco, na ordem em que já apareciam, nunca descartados.
        $orfaos = array_values(array_diff(array_keys($entrada), $ordemCampos));

        $linhas = [];
        foreach ([...$ordemCampos, ...$orfaos] as $campo) {
            if (!array_key_exists($campo, $entrada) || $entrada[$campo] === null) {
                continue;
            }
            $linhas[] = "        " . var_export($campo, true) . " => " . $this->exportValor($entrada[$campo]) . ",";
        }

        $corpo = implode("\n", $linhas);

        return "    " . var_export($modelo, true) . " => [\n{$corpo}\n    ],\n";
    }

    private function exportValor(mixed $valor): string
    {
        if (is_array($valor)) {
            $itens = array_map(fn($v) => var_export($v, true), $valor);
            return '[' . implode(', ', $itens) . ']';
        }
        return var_export($valor, true);
    }
}
