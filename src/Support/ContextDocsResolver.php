<?php

namespace Saviogodinho2002\DriftGuard\Support;

/**
 * Resolve documentos de regra de negócio (.md) que o app-host aponta pra complementar a análise de
 * um model — coisa que não é inferível só lendo o código (motivo histórico, decisão de produto,
 * exceção de negócio). O conteúdo entra como mais um "snippet" de contexto pro LLM, ao lado do
 * código, nunca substituindo — e nunca reescreve tabela/campos/relacoes (esses são sempre
 * reflection, mesmo que o doc diga outra coisa).
 *
 * Suporta 2 formas de apontar, combináveis:
 * (a) mapeamento explícito: config('driftguard.context_docs.map') = ['Despesa' => 'docs/regras/despesa.md']
 * (b) convenção por nome: config('driftguard.context_docs.convention_path') = 'docs/regras/{model}.md'
 *     — se o arquivo existir pro model em questão, é incluído automaticamente, sem precisar listar.
 */
class ContextDocsResolver
{
    public function __construct(
        private readonly string $basePath,
        /** @var array<string, string> Model => path relativo a $basePath */
        private readonly array $explicitMap = [],
        private readonly ?string $conventionPath = null,
    ) {
    }

    /**
     * @return array{path: string, content: string}|null Doc resolvido pro model, ou null se nenhum encontrado.
     *         Retorna também o PATH usado (não só o conteúdo) — o comando exibe isso na saída, pra
     *         quem rodou `analyze` conseguir auditar qual doc foi de fato usado (nunca silencioso).
     */
    public function resolveFor(string $modelo): ?array
    {
        $relativo = $this->explicitMap[$modelo] ?? $this->pathViaConvencao($modelo);
        if ($relativo === null) {
            return null;
        }

        $absoluto = rtrim($this->basePath, '/') . '/' . ltrim($relativo, '/');
        if (!is_file($absoluto)) {
            return null;
        }

        $conteudo = file_get_contents($absoluto);
        if ($conteudo === false) {
            return null;
        }

        return ['path' => $relativo, 'content' => $conteudo];
    }

    private function pathViaConvencao(string $modelo): ?string
    {
        if ($this->conventionPath === null) {
            return null;
        }

        return str_replace('{model}', $modelo, $this->conventionPath);
    }
}
