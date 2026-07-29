<?php

namespace Saviogodinho2002\Drifguard\Tests\Unit;

use Saviogodinho2002\Drifguard\Support\ContextDocsResolver;
use Saviogodinho2002\Drifguard\Tests\TestCase;

class ContextDocsResolverTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/drifguard_docs_' . uniqid();
        mkdir("{$this->tmpDir}/docs/regras", 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->tmpDir}/docs/regras/*.md") ?: []);
        @rmdir("{$this->tmpDir}/docs/regras");
        @rmdir("{$this->tmpDir}/docs");
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function test_resolves_via_explicit_map(): void
    {
        file_put_contents("{$this->tmpDir}/docs/regras/regra-especial.md", 'Regra de negócio especial.');

        $resolver = new ContextDocsResolver(
            basePath: $this->tmpDir,
            explicitMap: ['Post' => 'docs/regras/regra-especial.md'],
        );

        $doc = $resolver->resolveFor('Post');

        $this->assertNotNull($doc);
        $this->assertSame('Regra de negócio especial.', $doc['content']);
        $this->assertSame('docs/regras/regra-especial.md', $doc['path']);
    }

    public function test_resolves_via_naming_convention(): void
    {
        file_put_contents("{$this->tmpDir}/docs/regras/Post.md", 'Regra por convenção.');

        $resolver = new ContextDocsResolver(
            basePath: $this->tmpDir,
            conventionPath: 'docs/regras/{model}.md',
        );

        $doc = $resolver->resolveFor('Post');

        $this->assertSame('Regra por convenção.', $doc['content']);
    }

    public function test_returns_null_when_nothing_configured_or_found(): void
    {
        $resolver = new ContextDocsResolver(basePath: $this->tmpDir);

        $this->assertNull($resolver->resolveFor('Post'));
    }

    public function test_convention_miss_returns_null_not_error(): void
    {
        $resolver = new ContextDocsResolver(basePath: $this->tmpDir, conventionPath: 'docs/regras/{model}.md');

        $this->assertNull($resolver->resolveFor('ModelSemDoc'));
    }
}
