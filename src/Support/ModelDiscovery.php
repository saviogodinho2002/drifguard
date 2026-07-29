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
