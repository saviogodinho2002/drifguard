<?php

namespace Saviogodinho2002\Drifguard\Support;

/**
 * Casamento de chaves balanceado ({}) — usado sempre que é preciso extrair um bloco cujo tamanho
 * não dá pra prever por regex simples (método com chaves aninhadas dentro). Compartilhado entre
 * `ModelDiscovery::extractRelevantMethods()` e `ScopeClassWriter::sanitize()`.
 */
final class BraceMatcher
{
    /**
     * @param int $posicaoChaveAbertura Índice do caractere `{` de abertura em $conteudo.
     * @return int|null Índice do `}` que fecha essa chave, ou null se o conteúdo termina sem fechar.
     */
    public static function fechamentoDe(string $conteudo, int $posicaoChaveAbertura): ?int
    {
        $profundidade = 0;
        $tamanho      = strlen($conteudo);

        for ($i = $posicaoChaveAbertura; $i < $tamanho; $i++) {
            if ($conteudo[$i] === '{') {
                $profundidade++;
            } elseif ($conteudo[$i] === '}') {
                $profundidade--;
                if ($profundidade === 0) {
                    return $i;
                }
            }
        }

        return null;
    }
}
