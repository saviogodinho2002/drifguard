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

    // ── extração precisa reconhecer QUALQUER assinatura de método ──────────────

    private const ASSINATURAS_VARIADAS = <<<'PHP'
    <?php
    class Bicho
    {
        public function semRetorno()
        {
            return 1;
        }

        public function comRetornoUniao(): int|string
        {
            return 1;
        }

        public function comRetornoNamespaced(): \App\Models\Coisa
        {
            return new \App\Models\Coisa();
        }

        public function comRetornoIntersection(): \Countable&\ArrayAccess
        {
            return $this;
        }

        public static function estatico(): array
        {
            return [];
        }
    }
    PHP;

    public function test_extract_named_methods_recognizes_every_return_type_form_including_intersection(): void
    {
        $corpos = $this->discovery->extractNamedMethods(self::ASSINATURAS_VARIADAS, [
            'semRetorno', 'comRetornoUniao', 'comRetornoNamespaced', 'comRetornoIntersection', 'estatico',
        ]);

        $this->assertArrayHasKey('semRetorno', $corpos);
        $this->assertArrayHasKey('comRetornoUniao', $corpos);
        $this->assertArrayHasKey('comRetornoNamespaced', $corpos);
        $this->assertArrayHasKey('comRetornoIntersection', $corpos, 'tipo de retorno de interseção (PHP 8.1+, ex: Countable&ArrayAccess) não pode desaparecer silenciosamente da extração');
        $this->assertArrayHasKey('estatico', $corpos);
    }

    // ── packWithinBudget() — nunca corta método no meio (regra D2) ─────────────

    public function test_pack_within_budget_returns_everything_when_it_all_fits(): void
    {
        $corpos = ['a' => str_repeat('x', 100), 'b' => str_repeat('y', 100)];

        $resultado = $this->discovery->packWithinBudget($corpos, 1000);

        $this->assertStringContainsString(str_repeat('x', 100), $resultado);
        $this->assertStringContainsString(str_repeat('y', 100), $resultado);
        $this->assertStringNotContainsString('descartado', $resultado);
    }

    public function test_pack_within_budget_never_leaves_output_empty_even_when_single_method_exceeds_budget(): void
    {
        $corpos = ['unico' => str_repeat('u', 2000)];

        $resultado = $this->discovery->packWithinBudget($corpos, 500);

        $this->assertSame(2000, substr_count($resultado, 'u'), 'o método único inteiro tem que sobreviver, mesmo estourando o orçamento');
    }

    public function test_pack_within_budget_prioritizes_the_largest_method_then_backfills_smaller_ones(): void
    {
        $corpos = [
            'm1' => str_repeat('1', 100),
            'm2' => str_repeat('2', 200),
            'm3' => str_repeat('3', 300),
            'm4' => str_repeat('4', 400),
        ];

        $resultado = $this->discovery->packWithinBudget($corpos, 500);

        // maior (m4=400) entra primeiro, sobra 100 -- m1 (100) faz backfill exato; m2/m3 não cabem mais
        $this->assertStringContainsString(str_repeat('4', 400), $resultado);
        $this->assertStringContainsString(str_repeat('1', 100), $resultado);
        $this->assertStringNotContainsString(str_repeat('2', 200), $resultado);
        $this->assertStringNotContainsString(str_repeat('3', 300), $resultado);
        $this->assertStringContainsString('2 método(s) descartado(s)', $resultado);
        // nomes exatos precisam aparecer no aviso, senão a IA não tem como pedir de volta via request_method
        $this->assertStringContainsString('m3', $resultado);
        $this->assertStringContainsString('m2', $resultado);
        $this->assertStringContainsString('request_method', $resultado);
    }

    public function test_pack_within_budget_backfill_can_keep_many_small_methods_alongside_one_large(): void
    {
        $corpos = array_merge(
            ['grande' => str_repeat('G', 5000)],
            array_combine(
                array_map(fn($i) => "pequeno{$i}", range(1, 10)),
                array_fill(0, 10, str_repeat('p', 80)),
            ),
        );

        $resultado = $this->discovery->packWithinBudget($corpos, 6000);

        $this->assertSame(5000, substr_count($resultado, 'G'));
        $this->assertSame(800, substr_count($resultado, 'p'), 'todos os 10 pequenos (80 cada) cabem no espaço que sobrou: 5000+800=5800<=6000');
        $this->assertStringNotContainsString('descartado', $resultado);
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
