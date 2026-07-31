<?php

namespace Saviogodinho2002\DriftGuard\Support;

use Illuminate\Support\Facades\Log;

/**
 * Trava um diretório contra escrita no nível do SISTEMA DE ARQUIVOS antes do harness-CLI explorar
 * o código (`CliHarnessAnalysisClient`) — funciona independente de qual CLI é usada ou se ela tem
 * flag própria de allowlist de tool (Gemini CLI/opencode hoje não têm nenhuma confirmada). É a
 * camada PRINCIPAL de defesa durante a exploração; `--allowedTools` de cada CLI e a denylist de
 * código em `CliHarnessAnalysisClient` são reforço adicional, não substituto.
 *
 * Validado com probe real antes de escrever isto (arquivos de modo original DIFERENTE numa mesma
 * árvore, 644 e 600): leitura/`scandir()` continuam funcionando normalmente enquanto travado (o
 * Read/Grep do harness não quebra), escrita/`unlink()` são bloqueados de verdade pelo SO, e
 * `destravar()` restaura o modo EXATO de cada entrada — não um valor genérico tipo `u+w`.
 *
 * **Não funciona em Windows** — a documentação oficial do PHP diz que `chmod()` no Windows não
 * suporta o modelo de bits Unix, e um diretório marcado como somente-leitura no NTFS normalmente
 * não impede criar arquivo novo dentro dele (atributo cosmético pro Explorer, não enforcement real
 * como é em diretório POSIX). Em vez de fingir uma garantia que não se sustenta, `travar()` vira
 * no-op em Windows — a defesa nessa plataforma fica só por conta de `--allowedTools`/denylist.
 */
class ReadOnlyLock
{
    /** @var string[] Nunca trava essas subpastas mesmo se estiverem dentro do path travado — travar storage/ quebraria log/cache/sessão de requests concorrentes. */
    private const EXCLUSOES = ['storage', 'bootstrap/cache', 'vendor', 'node_modules', '.git'];

    public function __construct(
        /** Injetável só pra teste determinístico — default é o SO real. */
        private readonly bool $isWindows = PHP_OS_FAMILY === 'Windows',
    ) {
    }

    /**
     * @return array<string, int> modo original de cada entrada travada, chaveado por path —
     *         passar pra `destravar()` depois. Array vazio = nada foi travado (Windows, ou o path
     *         não existe) — `destravar()` de um array vazio é sempre um no-op seguro.
     */
    public function travar(string $path): array
    {
        if ($this->isWindows || !is_dir($path)) {
            return [];
        }

        $modosOriginais = [];

        try {
            $this->coletarModos($path, $modosOriginais);
        } catch (\Throwable $e) {
            Log::warning('[driftguard] ReadOnlyLock: falha ao inspecionar diretório, seguindo sem travar', [
                'path' => $path,
                'erro' => $e->getMessage(),
            ]);
            return [];
        }

        $travados = [];
        foreach ($modosOriginais as $p => $modo) {
            if (@chmod($p, $modo & ~0222)) {
                $travados[$p] = $modo;
            }
        }

        if (empty($travados) && !empty($modosOriginais)) {
            Log::warning('[driftguard] ReadOnlyLock: chmod falhou pra todas as entradas, seguindo sem travar', ['path' => $path]);
        }

        return $travados;
    }

    /** @param array<string, int> $modosOriginais */
    public function destravar(array $modosOriginais): void
    {
        foreach ($modosOriginais as $p => $modo) {
            @chmod($p, $modo);
        }
    }

    /** @param array<string, int> $modos */
    private function coletarModos(string $path, array &$modos): void
    {
        $modos[$path] = fileperms($path);

        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            if ($this->ePastaExcluida("{$path}/{$item}")) {
                continue;
            }

            $this->coletarModos("{$path}/{$item}", $modos);
        }
    }

    private function ePastaExcluida(string $path): bool
    {
        foreach (self::EXCLUSOES as $exclusao) {
            if (str_ends_with($path, "/{$exclusao}")) {
                return true;
            }
        }
        return false;
    }
}
