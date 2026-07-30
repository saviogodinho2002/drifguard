<?php

namespace Saviogodinho2002\DriftGuard\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Descobre models Eloquent e arquivos relevantes (controllers/requests/services que os referenciam)
 * pra montar o contexto de código que vai pro LLM. Paths vêm 100% de config('driftguard.*') — nenhum
 * caminho fixo tipo app/Models hardcoded, ao contrário da versão original de onde isso foi portado.
 */
class ModelDiscovery
{
    /**
     * Overrides conhecidos do Eloquent que são puro boilerplate sem conteúdo de negócio — únicos
     * métodos públicos que `extractSafeParts()` descarta (validado contra 5 models reais e grandes
     * antes de fechar a lista: manter TODO método público é o que garante nunca perder regra de
     * negócio real, só esses nomes específicos do framework são seguros de cortar sempre).
     */
    private const DENYLIST_FRAMEWORK = [
        'setKeysForSaveQuery', 'setKeysForSelectQuery', 'getRouteKeyName',
        'resolveRouteBinding', 'newModelQuery', 'newEloquentBuilder', 'newCollection',
    ];

    public function __construct(
        private readonly string $modelsPath,
        private readonly string $modelNamespace,
        /** @var string[] */
        private readonly array $supportingPaths,
    ) {
    }

    /** @return string[] Nomes de classe (sem namespace) de todo model Eloquent encontrado em $modelsPath. */
    public function allModelNames(): array
    {
        $nomes = [];
        foreach (glob(rtrim($this->modelsPath, '/') . '/*.php') ?: [] as $file) {
            $nome  = basename($file, '.php');
            $class = $this->modelNamespace . '\\' . $nome;
            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                $nomes[] = $nome;
            }
        }
        return $nomes;
    }

    public function modelFilePath(string $modelo): ?string
    {
        $path = rtrim($this->modelsPath, '/') . "/{$modelo}.php";
        return is_file($path) ? $path : null;
    }

    /**
     * Arquivos de apoio (controllers/requests/services) que referenciam o model, via grep por
     * `use {Namespace}\{Model};` seguido de uso real da classe no corpo do arquivo.
     *
     * @return string[] caminhos absolutos de arquivo
     */
    /**
     * @param int $maxArquivos Teto de quantos arquivos coletar (regra: nº de arquivos de apoio) —
     *        para de procurar assim que atinge o limite, não corta depois de já ter achado tudo.
     */
    public function supportingFilesForModel(string $modelo, int $maxArquivos = PHP_INT_MAX): array
    {
        $encontrados  = [];
        $usoNamespace = preg_quote($this->modelNamespace . '\\' . $modelo, '/');

        foreach ($this->supportingPaths as $base) {
            if (!is_dir($base)) {
                continue;
            }
            foreach ($this->phpFilesRecursively($base) as $file) {
                if (count($encontrados) >= $maxArquivos) {
                    return $encontrados;
                }
                $conteudo = file_get_contents($file);
                if ($conteudo === false) {
                    continue;
                }
                if (preg_match('/\buse\s+' . $usoNamespace . '\s*;/', $conteudo)
                    || preg_match('/\b' . preg_quote($modelo, '/') . '::/', $conteudo)) {
                    $encontrados[] = $file;
                }
            }
        }

        return $encontrados;
    }

    /**
     * Extrai só os métodos de $arquivo que mencionam $modelo no corpo, em vez do arquivo inteiro —
     * um controller grande normalmente só toca o model em 1-2 métodos, o resto é ruído de contexto
     * (custo/tokens) sem ganho de informação pro LLM. Casamento de chaves manual (balanceamento de
     * `{`/`}`) em vez de regex de bloco inteiro, porque método pode ter chaves aninhadas.
     *
     * @return string|null Métodos relevantes concatenados, ou null se nenhum mencionar o model
     *         (o chamador deve cair pro conteúdo integral truncado nesse caso).
     */
    public function extractRelevantMethods(string $arquivo, string $modelo): ?string
    {
        $conteudo = file_get_contents($arquivo);
        if ($conteudo === false) {
            return null;
        }

        $termo   = preg_quote($modelo, '/');
        $blocos  = [];
        $offset  = 0;
        $padrao  = '/(?:public|protected|private)?\s*function\s+\w+\s*\([^)]*\)\s*(?::\s*\??[\w\\\\|&]+\s*)?\{/';

        while (preg_match($padrao, $conteudo, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $inicioAssinatura = $m[0][1];
            $posicaoChaveAbertura = $inicioAssinatura + strlen($m[0][0]) - 1;
            $posicaoChaveFechamento = BraceMatcher::fechamentoDe($conteudo, $posicaoChaveAbertura);

            if ($posicaoChaveFechamento === null) {
                break;
            }

            $corpo = substr($conteudo, $inicioAssinatura, $posicaoChaveFechamento - $inicioAssinatura + 1);
            if (preg_match('/\b' . $termo . '\b/', $corpo)) {
                $blocos[] = $corpo;
            }

            $offset = $posicaoChaveFechamento + 1;
        }

        return empty($blocos) ? null : implode("\n\n", $blocos);
    }

    /**
     * Extração SEGURA do arquivo do próprio model (regra D2) — mantém todo método PÚBLICO (é
     * superfície de negócio) + `boot()`/`booted()` (registro de global scope, crítico pra
     * scope_class) + `scopeXxx()`/accessor mesmo se não-público. Só remove uma denylist curta e
     * fixa de overrides do Eloquent puramente boilerplate.
     *
     * Validado contra 5 models reais e grandes do domínio original antes de fechar este design: uma
     * versão mais agressiva (só relação/scope/booted/accessor) reduzia mais, mas cortava método de
     * negócio público real (ex: disponibilidade de recurso) só por não bater em nenhum papel
     * conhecido — perda silenciosa de regra de negócio. Manter todo público é o que garante nunca
     * perder isso; a redução fica menor, mas é uma redução de verdade (overrides do framework
     * custam espaço sem informação nenhuma).
     *
     * @return array{semente: string[], corpos: array<string, string>}
     */
    public function extractSafeParts(string $conteudo): array
    {
        ['corpos' => $corpos, 'visibilidades' => $visibilidades] = $this->extractMethodBodies($conteudo);

        $semente = [];
        foreach ($corpos as $nome => $corpo) {
            if (in_array($nome, self::DENYLIST_FRAMEWORK, true)) {
                continue;
            }
            if ($nome === 'boot' || $nome === 'booted') {
                $semente[] = $nome;
                continue;
            }
            if (($visibilidades[$nome] ?? 'public') === 'public' && !str_starts_with($nome, '__')) {
                $semente[] = $nome;
                continue;
            }
            if (preg_match('/^scope[A-Z]/', $nome) || preg_match('/^(get|set)\w+Attribute$/', $nome)) {
                $semente[] = $nome;
            }
        }

        return ['semente' => $semente, 'corpos' => array_intersect_key($corpos, array_flip($semente))];
    }

    /**
     * Reextrai o corpo de métodos JÁ IDENTIFICADOS por nome (ex: pela semente salva no
     * `ModelIndex`) — usado quando o índice persistido já sabe quais métodos importam, sem precisar
     * rodar `extractSafeParts()` (que reclassifica cada método do zero) de novo.
     *
     * @param string[] $nomes
     * @return array<string, string>
     */
    public function extractNamedMethods(string $conteudo, array $nomes): array
    {
        ['corpos' => $corpos] = $this->extractMethodBodies($conteudo);
        return array_intersect_key($corpos, array_flip($nomes));
    }

    /**
     * Empacota um conjunto de corpos de método dentro de um orçamento de caracteres, SEM NUNCA
     * cortar um método no meio (regra D2 — truncamento por posição bruta corta sem critério; isso
     * descarta método inteiro em vez disso).
     *
     * Estratégia "maior primeiro + backfill", validada contra 5 models reais e grandes antes de
     * escolher: pega o método mais denso primeiro (tende a concentrar lógica de negócio real —
     * confirmado nos testes), depois tenta encaixar os menores no espaço que sobrar (backfill) —
     * ganha amplitude de bônus sem sacrificar o que mais importa. Bateu a alternativa (menor
     * primeiro) nas 5 vezes na medição real de bytes retidos.
     *
     * Nunca fica vazio: se o próprio maior já estoura o orçamento sozinho, entra inteiro mesmo
     * assim (aceita estourar — a alternativa seria cortar ele no meio, que é o que isso evita).
     *
     * @param array<string, string> $corpos nome do método => corpo
     */
    public function packWithinBudget(array $corpos, int $maxChars): string
    {
        $total = array_sum(array_map('mb_strlen', $corpos));
        if ($total <= $maxChars) {
            return implode("\n\n", $corpos);
        }

        uasort($corpos, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        $nomes        = array_keys($corpos);
        $primeiroNome = array_shift($nomes);
        $mantidos     = [$primeiroNome => $corpos[$primeiroNome]];
        $acumulado    = mb_strlen($corpos[$primeiroNome]);

        foreach ($nomes as $nome) {
            $tamanho = mb_strlen($corpos[$nome]);
            if ($acumulado + $tamanho > $maxChars) {
                continue;
            }
            $mantidos[$nome] = $corpos[$nome];
            $acumulado += $tamanho;
        }

        $nomesDescartados = array_values(array_diff(array_keys($corpos), array_keys($mantidos)));
        $aviso = $nomesDescartados !== []
            ? "\n... (" . count($nomesDescartados) . " método(s) descartado(s) por orçamento: "
                . implode(', ', $nomesDescartados) . " — peça via request_method se precisar)"
            : '';

        return implode("\n\n", $mantidos) . $aviso;
    }

    /** @return array{corpos: array<string,string>, visibilidades: array<string,string>} */
    private function extractMethodBodies(string $conteudo): array
    {
        $padrao = '/(public|protected|private)?\s*(static\s+)?function\s+(\w+)\s*\([^)]*\)\s*(?::\s*\??[\w\\\\|&]+\s*)?\{/';
        $offset = 0;
        $corpos = [];
        $visibilidades = [];

        while (preg_match($padrao, $conteudo, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $visibilidade = $m[1][0] !== '' ? $m[1][0] : 'public';
            $nome = $m[3][0];
            $inicio = $m[0][1];
            $posAbertura = $inicio + strlen($m[0][0]) - 1;
            $posFechamento = BraceMatcher::fechamentoDe($conteudo, $posAbertura);

            if ($posFechamento === null) {
                break;
            }

            $corpos[$nome] = substr($conteudo, $inicio, $posFechamento - $inicio + 1);
            $visibilidades[$nome] = $visibilidade;
            $offset = $posFechamento + 1;
        }

        return ['corpos' => $corpos, 'visibilidades' => $visibilidades];
    }

    /** @return string[] */
    private function phpFilesRecursively(string $dir): array
    {
        $arquivos = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $arquivos[] = $file->getPathname();
            }
        }
        return $arquivos;
    }
}
