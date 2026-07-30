<?php

namespace Saviogodinho2002\DriftGuard\Support;

/**
 * Índice persistido por model (`storage_path/index/{Model}.json`), mesmo princípio de
 * `context.json` (compartilhado via git) — evita recomputar `extractSafeParts()` do zero a cada
 * rodada quando o arquivo do model não mudou desde a última vez. Invalidado por HASH de conteúdo,
 * nunca por timestamp (checkout/clone muda mtime sem mudar conteúdo). Também serve de
 * auditabilidade: um dev pode abrir `driftguard/index/Contract.json` e ver exatamente quais
 * métodos foram considerados relevantes, sem precisar re-derivar mentalmente.
 */
class ModelIndex
{
    public function __construct(
        private readonly string $storagePath,
    ) {
    }

    private function path(string $modelo): string
    {
        return rtrim($this->storagePath, '/') . "/index/{$modelo}.json";
    }

    /**
     * @return array{hash_arquivo: string, atualizado_em: string, metodos_semente: string[], tamanho_arquivo: int, tamanho_extraido: int}|null
     *         null se não existe índice ainda, ou se o arquivo estiver corrompido (json inválido) —
     *         nesse caso o chamador trata como se não existisse e recomputa, nunca quebra.
     */
    public function read(string $modelo): ?array
    {
        $path = $this->path($modelo);
        if (!is_file($path)) {
            return null;
        }

        $dados = json_decode(file_get_contents($path) ?: '', true);
        return is_array($dados) ? $dados : null;
    }

    public function write(string $modelo, array $dados): void
    {
        $path = $this->path($modelo);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** true se não há índice ainda, ou se o hash guardado não bate mais com o conteúdo atual. */
    public function isStale(string $modelo, string $conteudoAtual): bool
    {
        $indice = $this->read($modelo);
        return $indice === null || ($indice['hash_arquivo'] ?? null) !== hash('sha256', $conteudoAtual);
    }
}
