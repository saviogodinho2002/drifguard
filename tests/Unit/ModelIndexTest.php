<?php

namespace Saviogodinho2002\DriftGuard\Tests\Unit;

use Saviogodinho2002\DriftGuard\Support\ModelIndex;
use Saviogodinho2002\DriftGuard\Tests\TestCase;

class ModelIndexTest extends TestCase
{
    private string $tmpDir;
    private ModelIndex $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/driftguard_index_' . uniqid();
        $this->index  = new ModelIndex(storagePath: $this->tmpDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->tmpDir}/index/*.json") ?: []);
        @rmdir("{$this->tmpDir}/index");
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function test_no_index_yet_is_stale(): void
    {
        $this->assertNull($this->index->read('Contract'));
        $this->assertTrue($this->index->isStale('Contract', '<?php class Contract {}'));
    }

    public function test_matching_hash_is_not_stale(): void
    {
        $conteudo = '<?php class Contract { public function coordinator() {} }';

        $this->index->write('Contract', [
            'hash_arquivo'     => hash('sha256', $conteudo),
            'atualizado_em'    => date(DATE_ATOM),
            'metodos_semente'  => ['coordinator'],
            'tamanho_arquivo'  => strlen($conteudo),
            'tamanho_extraido' => 10,
        ]);

        $this->assertFalse($this->index->isStale('Contract', $conteudo));

        $lido = $this->index->read('Contract');
        $this->assertSame(['coordinator'], $lido['metodos_semente']);
    }

    public function test_changed_content_is_stale(): void
    {
        $original = '<?php class Contract { public function coordinator() {} }';
        $this->index->write('Contract', [
            'hash_arquivo'     => hash('sha256', $original),
            'atualizado_em'    => date(DATE_ATOM),
            'metodos_semente'  => ['coordinator'],
            'tamanho_arquivo'  => strlen($original),
            'tamanho_extraido' => 10,
        ]);

        $mudado = $original . "\n// mudou algo";

        $this->assertTrue($this->index->isStale('Contract', $mudado));
    }

    public function test_corrupted_index_file_is_treated_as_missing_not_a_crash(): void
    {
        mkdir("{$this->tmpDir}/index", 0755, true);
        file_put_contents("{$this->tmpDir}/index/Contract.json", '{isso nao e json valido');

        $this->assertNull($this->index->read('Contract'));
        $this->assertTrue($this->index->isStale('Contract', 'qualquer coisa'));
    }
}
