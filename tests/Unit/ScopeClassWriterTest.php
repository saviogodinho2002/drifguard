<?php

namespace Saviogodinho2002\Drifguard\Tests\Unit;

use Saviogodinho2002\Drifguard\Support\ScopeClassWriter;
use Saviogodinho2002\Drifguard\Tests\TestCase;

class ScopeClassWriterTest extends TestCase
{
    private ScopeClassWriter $writer;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/drifguard_scopes_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->writer = new ScopeClassWriter(outputPath: $this->tmpDir, namespace: 'App\\Drifguard\\Scopes');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->tmpDir}/*.php") ?: []);
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function test_writes_valid_class_first_time(): void
    {
        $resultado = $this->writer->write('Post', 'return $query->where(\'author_id\', $context->id);', null);

        $this->assertSame('written', $resultado['status']);
        $this->assertFileExists($resultado['path']);
        $this->assertStringContainsString('class PostScope implements TenantScope', file_get_contents($resultado['path']));
    }

    /** Gap #3 da revisão do plano: nunca grava PHP que não parseia. */
    public function test_rejects_invalid_php_body_without_writing_file(): void
    {
        $resultado = $this->writer->write('Post', 'isso nao e ($php valido {{{', null);

        $this->assertSame('syntax_error', $resultado['status']);
        $this->assertFileDoesNotExist($this->writer->classPathFor('Post'));
    }

    /** Gap #2 da revisão do plano: arquivo editado à mão não pode ser sobrescrito silenciosamente. */
    public function test_detects_manual_edit_and_skips_overwrite(): void
    {
        $primeiro = $this->writer->write('Post', 'return $query;', null);
        $this->assertSame('written', $primeiro['status']);

        // simula edição manual do dev no arquivo gerado
        file_put_contents($primeiro['path'], file_get_contents($primeiro['path']) . "\n// editado à mão\n");

        $segundo = $this->writer->write('Post', 'return $query->where(\'x\', 1);', $primeiro['hash']);

        $this->assertSame('skipped_manual_edit', $segundo['status']);
        $this->assertStringContainsString('editado manualmente', $segundo['message']);
    }

    public function test_regenerates_when_hash_matches_last_generated(): void
    {
        $primeiro = $this->writer->write('Post', 'return $query;', null);

        $segundo = $this->writer->write('Post', 'return $query->where(\'x\', 1);', $primeiro['hash']);

        $this->assertSame('written', $segundo['status']);
        $this->assertStringContainsString("where('x', 1)", file_get_contents($segundo['path']));
    }
}
