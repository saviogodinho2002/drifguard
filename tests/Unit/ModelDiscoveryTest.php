<?php

namespace Saviogodinho2002\DriftGuard\Tests\Unit;

use Saviogodinho2002\DriftGuard\Support\ModelDiscovery;
use Saviogodinho2002\DriftGuard\Tests\TestCase;

class ModelDiscoveryTest extends TestCase
{
    private string $tmpDir;
    private ModelDiscovery $discovery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/driftguard_discovery_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->discovery = new ModelDiscovery(
            modelsPath: $this->tmpDir,
            modelNamespace: 'App\\Models',
            supportingPaths: [],
        );
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->tmpDir}/*") ?: []);
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function test_extracts_only_methods_mentioning_the_model(): void
    {
        $arquivo = "{$this->tmpDir}/PostController.php";
        file_put_contents($arquivo, <<<PHP
        <?php
        class PostController
        {
            public function index()
            {
                \$authors = Author::all();
                return \$authors;
            }

            public function show(\$id)
            {
                \$post = Post::findOrFail(\$id);
                return \$post;
            }
        }
        PHP);

        $resultado = $this->discovery->extractRelevantMethods($arquivo, 'Post');

        $this->assertNotNull($resultado);
        $this->assertStringContainsString('function show', $resultado);
        $this->assertStringNotContainsString('function index', $resultado);
    }

    public function test_returns_null_when_no_method_mentions_the_model(): void
    {
        $arquivo = "{$this->tmpDir}/AuthorController.php";
        file_put_contents($arquivo, <<<PHP
        <?php
        class AuthorController
        {
            public function index()
            {
                return Author::all();
            }
        }
        PHP);

        $this->assertNull($this->discovery->extractRelevantMethods($arquivo, 'Post'));
    }

    public function test_handles_nested_braces_inside_relevant_method(): void
    {
        $arquivo = "{$this->tmpDir}/PostService.php";
        file_put_contents($arquivo, <<<PHP
        <?php
        class PostService
        {
            public function publish(\$id)
            {
                if (true) {
                    foreach ([1, 2] as \$i) {
                        Post::find(\$id)->update(['status' => 'published']);
                    }
                }
            }
        }
        PHP);

        $resultado = $this->discovery->extractRelevantMethods($arquivo, 'Post');

        $this->assertNotNull($resultado);
        $this->assertStringContainsString('published', $resultado);
        // o bloco extraído precisa fechar todas as chaves aninhadas, não parar na primeira '}'
        $this->assertSame(substr_count($resultado, '{'), substr_count($resultado, '}'));
    }

    // ── extractSafeParts() — extração segura do arquivo do PRÓPRIO model ──────

    private const MODELO_GRANDE = <<<'PHP'
    <?php
    class Contract
    {
        public function coordinator()
        {
            return $this->belongsTo(Coordinator::class);
        }

        public function scopeOwns($query, $user)
        {
            return $query->where('coordinator_id', $user->id);
        }

        protected static function booted()
        {
            static::addGlobalScope(new TenantScope());
        }

        public function getStatusLabelAttribute()
        {
            return ucfirst($this->status);
        }

        public function comodosDisponiveis()
        {
            return $this->hasMany(Comodo::class)->where('disponivel', true);
        }

        private function calcularAlgoInterno()
        {
            return 1 + 1;
        }

        public function setKeysForSaveQuery($query)
        {
            return $query;
        }
    }
    PHP;

    public function test_extract_safe_parts_keeps_all_public_business_methods(): void
    {
        $resultado = $this->discovery->extractSafeParts(self::MODELO_GRANDE);

        $this->assertContains('coordinator', $resultado['semente']);
        $this->assertContains('comodosDisponiveis', $resultado['semente']);
        $this->assertStringContainsString('belongsTo', $resultado['corpos']['coordinator']);
    }

    public function test_extract_safe_parts_keeps_booted_scope_and_accessor_even_if_not_public(): void
    {
        $resultado = $this->discovery->extractSafeParts(self::MODELO_GRANDE);

        $this->assertContains('booted', $resultado['semente']);
        $this->assertContains('scopeOwns', $resultado['semente']);
        $this->assertContains('getStatusLabelAttribute', $resultado['semente']);
    }

    public function test_extract_safe_parts_removes_only_known_eloquent_framework_overrides(): void
    {
        $resultado = $this->discovery->extractSafeParts(self::MODELO_GRANDE);

        $this->assertNotContains('setKeysForSaveQuery', $resultado['semente']);
    }

    public function test_extract_safe_parts_drops_private_helper_matching_no_known_role(): void
    {
        $resultado = $this->discovery->extractSafeParts(self::MODELO_GRANDE);

        $this->assertNotContains('calcularAlgoInterno', $resultado['semente']);
    }

    public function test_extract_named_methods_reextracts_only_the_given_names(): void
    {
        $corpos = $this->discovery->extractNamedMethods(self::MODELO_GRANDE, ['coordinator', 'scopeOwns']);

        $this->assertArrayHasKey('coordinator', $corpos);
        $this->assertArrayHasKey('scopeOwns', $corpos);
        $this->assertArrayNotHasKey('booted', $corpos);
        $this->assertArrayNotHasKey('comodosDisponiveis', $corpos);
    }

    // ── supportingFilesForModel() — limite de nº de arquivos (regra D4) ────────

    public function test_supporting_files_for_model_stops_at_max_count(): void
    {
        $dir = "{$this->tmpDir}/controllers";
        mkdir($dir, 0755, true);
        for ($i = 1; $i <= 4; $i++) {
            file_put_contents("{$dir}/Controller{$i}.php", "<?php\nclass Controller{$i} { public function x() { return Post::all(); } }");
        }

        $discovery = new ModelDiscovery(
            modelsPath: $this->tmpDir,
            modelNamespace: 'App\\Models',
            supportingPaths: [$dir],
        );

        $encontrados = $discovery->supportingFilesForModel('Post', maxArquivos: 2);

        $this->assertCount(2, $encontrados);

        array_map('unlink', glob("{$dir}/*.php") ?: []);
        rmdir($dir);
    }
}
