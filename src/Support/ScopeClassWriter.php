<?php

namespace Saviogodinho2002\Drifguard\Support;

use Illuminate\Support\Str;

/**
 * Gera/atualiza o arquivo .php de uma classe de escopo (FieldSpec tipo scope_class) — nunca grava
 * uma string de código pra ser eval()'d depois. 3 guarda-corpos obrigatórios, nessa ordem (achados
 * de revisão do plano + de um relatório real de produção, mesma classe de risco que já mordeu este
 * domínio antes):
 *
 * 1. Sanitização de FORMATO (`sanitize()`) — remove cerca markdown, `use` solto, e assinatura de
 *    método reincluída. Puramente estrutural, nunca reescreve nome de variável nem lógica.
 * 2. Checagem SEMÂNTICA (`checarUsoDeVariaveis()`) — depois de sanitizar, o corpo pode ficar
 *    sintaticamente válido mas usar os parâmetros errados (ex: `$builder`/`$model` em vez de
 *    `$query`/`$context`, ou ignorar `$context` e chamar `auth()->user()` direto). Isso é MAIS
 *    perigoso que um erro de sintaxe: `php -l` sozinho aceita esse código, e o resultado seria uma
 *    classe de escopo que compila mas não restringe nada — vazamento cross-tenant silencioso. Por
 *    isso essa checagem roda sempre, mesmo quando a sintaxe já está OK.
 * 3. Checagem sintática (`php -l`) — nunca escreve PHP que não parseia.
 *
 * Guarda-corpo à parte (não relacionado a conteúdo): se o arquivo já existe e diverge do hash da
 * última geração conhecida, trata como EDITADO À MÃO — não sobrescreve silenciosamente, devolve
 * status pro chamador decidir (perguntar/confirmar).
 */
class ScopeClassWriter
{
    public function __construct(
        private readonly string $outputPath,
        private readonly string $namespace,
    ) {
    }

    /**
     * Sanitização de FORMATO — nunca semântica (nunca renomeia variável, nunca insere `return`).
     * Remove só o que é inequivocamente inválido/redundante num corpo de método:
     *
     * 1. Cerca de código markdown envolvendo o conteúdo inteiro.
     * 2. Linhas `use Algo\Namespace;` soltas no início (inválidas fora do topo de um arquivo — se a
     *    IA precisar referenciar uma classe, o contrato pede nome totalmente qualificado).
     * 3. Assinatura de método completa reincluída (`function apply(...) { ... }` envolvendo tudo) —
     *    extrai só o miolo via casamento de chaves balanceado.
     */
    public function sanitize(string $bruto): string
    {
        $conteudo = trim($bruto);

        if (preg_match('/^```(?:php)?\s*\n(.*)\n```\s*$/s', $conteudo, $m)) {
            $conteudo = trim($m[1]);
        }

        $conteudo = trim(preg_replace('/^(?:\s*use\s+[\w\\\\]+(?:\s+as\s+\w+)?;\s*\n)+/', '', $conteudo));

        $padrao = '/^(?:public|protected|private)?\s*function\s+\w*\s*\([^)]*\)\s*(?::\s*\??[\w\\\\|]+\s*)?\{/';
        if (preg_match($padrao, $conteudo, $m, PREG_OFFSET_CAPTURE) && $m[0][1] === 0) {
            $posAbertura   = strlen($m[0][0]) - 1;
            $posFechamento = BraceMatcher::fechamentoDe($conteudo, $posAbertura);

            if ($posFechamento !== null && trim(substr($conteudo, $posFechamento + 1)) === '') {
                $conteudo = trim(substr($conteudo, $posAbertura + 1, $posFechamento - $posAbertura - 1));
            }
        }

        return trim($conteudo);
    }

    /**
     * Guarda-corpo semântico (regra 2 da classe): heurística por regex, roda DEPOIS de sanitizar e
     * ANTES de aceitar como válido. @return string|null null se OK, mensagem de erro caso contrário.
     */
    private function checarUsoDeVariaveis(string $corpoSanitizado): ?string
    {
        if (!str_contains($corpoSanitizado, '$query')) {
            return "Corpo não referencia \$query (o parâmetro real de TenantScope::apply()) em nenhum lugar — não dá pra confirmar que a query é de fato restringida.";
        }

        if (preg_match('/\$builder\b/', $corpoSanitizado) || preg_match('/\$model\b/', $corpoSanitizado)) {
            return "Corpo usa \$builder/\$model — os nomes reais dos parâmetros de TenantScope::apply() são \$query e \$context.";
        }

        if (preg_match('/\b(?:auth\(\)\s*->\s*user\(\)|Auth::user\(\)|request\(\)\s*->\s*user\(\))/', $corpoSanitizado)) {
            return "Corpo ignora o \$context recebido e busca o usuário autenticado diretamente (auth()/Auth::user()/request()->user()) — use \$context, que já é passado pelo chamador.";
        }

        return null;
    }

    /**
     * Valida um corpo proposto SEM gravar nada em disco — usado pelo retry em tempo de análise
     * (`ModelSyncService::loopAnalise()`), que precisa saber se vale a pena pedir correção à IA
     * antes mesmo de a análise terminar.
     *
     * @return string|null null se o corpo (sanitizado) passaria em `write()`, mensagem de erro caso contrário.
     */
    public function validar(string $methodBodyBruto): ?string
    {
        $sanitizado = $this->sanitize($methodBodyBruto);

        $erroSemantico = $this->checarUsoDeVariaveis($sanitizado);
        if ($erroSemantico !== null) {
            return $erroSemantico;
        }

        return $this->checarSintaxe($this->renderClass('ValidacaoTemp', 'campo', $sanitizado));
    }

    /**
     * Inclui o nome do FIELD, não só do model — 2 fields scope_class no mesmo model (ex:
     * escopo_projeto + escopo_membro em Contract) precisam gerar arquivos/classes DIFERENTES.
     * Achado real de produção: quando o nome derivava só do model, o 2º field sobrescrevia
     * silenciosamente o arquivo que o 1º tinha acabado de escrever — sem erro, sem aviso.
     */
    public function classNameFor(string $modelo, string $fieldName): string
    {
        return $modelo . Str::studly($fieldName) . 'Scope';
    }

    public function classPathFor(string $modelo, string $fieldName): string
    {
        return rtrim($this->outputPath, '/') . '/' . $this->classNameFor($modelo, $fieldName) . '.php';
    }

    public function fqcnFor(string $modelo, string $fieldName): string
    {
        return $this->namespace . '\\' . $this->classNameFor($modelo, $fieldName);
    }

    /**
     * @param string $fieldName Nome do FieldSpec (tipo scope_class) que originou este corpo — entra
     *        no nome da classe gerada, pra 2 fields no mesmo model nunca colidirem no mesmo arquivo.
     * @param string $methodBody Corpo do método apply() proposto pela IA (bruto — write() sanitiza
     *        antes de validar, nunca assume que já chegou limpo).
     * @param string|null $lastGeneratedHash Hash sha256 do conteúdo gerado da última vez (guardado
     *        pelo chamador, ex: em context.json) — null quando é a 1ª geração pro model+field.
     * @return array{status: 'written'|'skipped_manual_edit'|'semantic_check_failed'|'syntax_error', message: ?string, hash: ?string, path: string, raw_body_preview: ?string}
     */
    public function write(string $modelo, string $fieldName, string $methodBody, ?string $lastGeneratedHash): array
    {
        $path = $this->classPathFor($modelo, $fieldName);

        if (is_file($path) && $lastGeneratedHash !== null) {
            $conteudoAtual = file_get_contents($path) ?: '';
            if (hash('sha256', $conteudoAtual) !== $lastGeneratedHash) {
                return [
                    'status'           => 'skipped_manual_edit',
                    'message'          => "Arquivo {$path} foi editado manualmente desde a última geração — não sobrescrito automaticamente. Revise o corpo proposto e aplique à mão se quiser incorporá-lo.",
                    'hash'             => null,
                    'path'             => $path,
                    'raw_body_preview' => null,
                ];
            }
        }

        $sanitizado = $this->sanitize($methodBody);

        $erroSemantico = $this->checarUsoDeVariaveis($sanitizado);
        if ($erroSemantico !== null) {
            return [
                'status'           => 'semantic_check_failed',
                'message'          => "{$modelo}: corpo proposto pela IA não passou na checagem semântica, não gravado: {$erroSemantico}",
                'hash'             => null,
                'path'             => $path,
                'raw_body_preview' => mb_substr($methodBody, 0, 300),
            ];
        }

        $conteudo = $this->renderClass($modelo, $fieldName, $sanitizado);

        $erroSintaxe = $this->checarSintaxe($conteudo);
        if ($erroSintaxe !== null) {
            return [
                'status'           => 'syntax_error',
                'message'          => "{$modelo}: corpo proposto pela IA não é PHP válido, não gravado: {$erroSintaxe}",
                'hash'             => null,
                'path'             => $path,
                'raw_body_preview' => mb_substr($methodBody, 0, 300),
            ];
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $conteudo);

        return [
            'status'           => 'written',
            'message'          => null,
            'hash'             => hash('sha256', $conteudo),
            'path'             => $path,
            'raw_body_preview' => null,
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

    private function renderClass(string $modelo, string $fieldName, string $methodBody): string
    {
        $className = $this->classNameFor($modelo, $fieldName);
        $namespace = $this->namespace;

        return <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\Database\Eloquent\Builder;
        use Saviogodinho2002\Drifguard\Contracts\TenantScope;

        /**
         * Gerado por drifguard a partir da análise do campo {$fieldName} do model {$modelo}. Editar
         * manualmente é seguro — a próxima geração detecta a mudança (hash do conteúdo) e pede
         * confirmação antes de sobrescrever, nunca substitui uma edição manual às cegas.
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
