<?php

namespace Saviogodinho2002\Drifguard\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Descobre models Eloquent e arquivos relevantes (controllers/requests/services que os referenciam)
 * pra montar o contexto de código que vai pro LLM. Paths vêm 100% de config('drifguard.*') — nenhum
 * caminho fixo tipo app/Models hardcoded, ao contrário da versão original de onde isso foi portado.
 */
class ModelDiscovery
{
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
    public function supportingFilesForModel(string $modelo): array
    {
        $encontrados  = [];
        $usoNamespace = preg_quote($this->modelNamespace . '\\' . $modelo, '/');

        foreach ($this->supportingPaths as $base) {
            if (!is_dir($base)) {
                continue;
            }
            foreach ($this->phpFilesRecursively($base) as $file) {
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
        $padrao  = '/(?:public|protected|private)?\s*function\s+\w+\s*\([^)]*\)\s*(?::\s*\??[\w\\\\|]+\s*)?\{/';

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
