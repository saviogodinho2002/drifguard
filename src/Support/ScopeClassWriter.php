<?php

namespace Saviogodinho2002\Drifguard\Support;

/**
 * Gera/atualiza o arquivo .php de uma classe de escopo (FieldSpec tipo scope_class) — nunca grava
 * uma string de código pra ser eval()'d depois. 2 guarda-corpos obrigatórios (achados de revisão
 * do plano, mesma classe de risco que já mordeu este domínio antes):
 *
 * 1. Checagem sintática (`php -l`) ANTES de gravar — nunca escreve PHP que não parseia.
 * 2. Se o arquivo já existe e diverge do hash da última geração conhecida, trata como EDITADO À MÃO
 *    — não sobrescreve silenciosamente, devolve status pro chamador decidir (perguntar/confirmar).
 */
class ScopeClassWriter
{
    public function __construct(
        private readonly string $outputPath,
        private readonly string $namespace,
    ) {
    }

    public function classNameFor(string $modelo): string
    {
        return "{$modelo}Scope";
    }

    public function classPathFor(string $modelo): string
    {
        return rtrim($this->outputPath, '/') . '/' . $this->classNameFor($modelo) . '.php';
    }

    public function fqcnFor(string $modelo): string
    {
        return $this->namespace . '\\' . $this->classNameFor($modelo);
    }

    /**
     * @param string $methodBody Corpo do método apply() proposto pela IA (só o miolo, sem assinatura).
     * @param string|null $lastGeneratedHash Hash sha256 do conteúdo gerado da última vez (guardado
     *        pelo chamador, ex: em context.json) — null quando é a 1ª geração pro model.
     * @return array{status: 'written'|'skipped_manual_edit'|'syntax_error', message: ?string, hash: ?string, path: string}
     */
    public function write(string $modelo, string $methodBody, ?string $lastGeneratedHash): array
    {
        $path = $this->classPathFor($modelo);

        if (is_file($path) && $lastGeneratedHash !== null) {
            $conteudoAtual = file_get_contents($path) ?: '';
            if (hash('sha256', $conteudoAtual) !== $lastGeneratedHash) {
                return [
                    'status'  => 'skipped_manual_edit',
                    'message' => "Arquivo {$path} foi editado manualmente desde a última geração — não sobrescrito automaticamente. Revise o corpo proposto e aplique à mão se quiser incorporá-lo.",
                    'hash'    => null,
                    'path'    => $path,
                ];
            }
        }

        $conteudo = $this->renderClass($modelo, $methodBody);

        $erroSintaxe = $this->checarSintaxe($conteudo);
        if ($erroSintaxe !== null) {
            return [
                'status'  => 'syntax_error',
                'message' => "Corpo proposto pela IA não é PHP válido, não gravado: {$erroSintaxe}",
                'hash'    => null,
                'path'    => $path,
            ];
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $conteudo);

        return [
            'status'  => 'written',
            'message' => null,
            'hash'    => hash('sha256', $conteudo),
            'path'    => $path,
        ];
    }

    /** @return string|null null se sintaxe OK, mensagem de erro caso contrário. Fail-open (não bloqueia) se `exec()` estiver indisponível no host. */
    private function checarSintaxe(string $conteudo): ?string
    {
        if (!function_exists('exec')) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'drifguard_scope_');
        file_put_contents($tmp, $conteudo);

        $saida  = [];
        $codigo = 0;
        exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $saida, $codigo);
        @unlink($tmp);

        return $codigo === 0 ? null : implode("\n", $saida);
    }

    private function renderClass(string $modelo, string $methodBody): string
    {
        $className = $this->classNameFor($modelo);
        $namespace = $this->namespace;

        return <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\Database\Eloquent\Builder;
        use Saviogodinho2002\Drifguard\Contracts\TenantScope;

        /**
         * Gerado por drifguard a partir da análise do model {$modelo}. Editar manualmente é seguro —
         * a próxima geração detecta a mudança (hash do conteúdo) e pede confirmação antes de
         * sobrescrever, nunca substitui uma edição manual às cegas.
         */
        class {$className} implements TenantScope
        {
            public function apply(Builder \$query, mixed \$context): Builder
            {
        {$methodBody}
            }
        }

        PHP;
    }
}
