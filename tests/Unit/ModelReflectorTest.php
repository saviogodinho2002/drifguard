<?php

namespace Saviogodinho2002\DriftGuard\Tests\Unit;

use Saviogodinho2002\DriftGuard\Support\ModelReflector;
use Saviogodinho2002\DriftGuard\Tests\TestCase;

class ModelReflectorTest extends TestCase
{
    private ModelReflector $reflector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reflector = new ModelReflector(modelNamespace: 'Saviogodinho2002\\DriftGuard\\Tests\\Fixtures\\Models');
    }

    public function test_metadata_for_reflects_real_model(): void
    {
        $meta = $this->reflector->metadataFor('Post');

        $this->assertNotNull($meta);
        $this->assertSame('posts', $meta['tabela']);
        $this->assertStringContainsString('title', $meta['campos']);
        $this->assertStringContainsString('author_id', $meta['campos']);
        $this->assertStringContainsString('author', $meta['relacoes']);
    }

    public function test_metadata_for_unknown_model_returns_null(): void
    {
        $this->assertNull($this->reflector->metadataFor('NaoExiste123'));
    }

    public function test_relation_targets_resolves_related_model_name(): void
    {
        $alvos = $this->reflector->relationTargets('Post');

        $this->assertArrayHasKey('author', $alvos);
        $this->assertSame('Author', $alvos['author']);
    }

    public function test_infra_columns_are_excluded_from_campos(): void
    {
        $meta   = $this->reflector->metadataFor('Post');
        $campos = array_map('trim', explode(',', $meta['campos']));

        $this->assertNotContains('id', $campos);
        $this->assertNotContains('created_at', $campos);
        $this->assertContains('author_id', $campos); // FK legítima, não é a PK "id" — não deve ser excluída
    }
}
