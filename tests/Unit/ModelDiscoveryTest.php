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
}
