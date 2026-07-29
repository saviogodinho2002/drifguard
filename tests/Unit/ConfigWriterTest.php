<?php

namespace Saviogodinho2002\DriftGuard\Tests\Unit;

use Saviogodinho2002\DriftGuard\Support\ConfigWriter;
use Saviogodinho2002\DriftGuard\Support\FieldSpec;
use Saviogodinho2002\DriftGuard\Tests\TestCase;

class ConfigWriterTest extends TestCase
{
    private ConfigWriter $writer;
    private string $tmpFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer  = new ConfigWriter();
        $this->tmpFile = sys_get_temp_dir() . '/driftguard_test_' . uniqid() . '.php';
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpFile);
        parent::tearDown();
    }

    public function test_merge_backfills_field_omitted_by_ai(): void
    {
        $current  = ['Post' => ['descricao' => 'já existia', 'notas' => 'nota antiga']];
        $proposal = ['Post' => ['descricao' => 'nova descrição', 'notas' => '']]; // IA "omitiu" notas (vazio)

        $merged = $this->writer->mergeProposal($current, $proposal);

        $this->assertSame('nova descrição', $merged['Post']['descricao']);
        $this->assertSame('nota antiga', $merged['Post']['notas']); // preservado, não apagado
    }

    /** Gap #1 da revisão do plano: campo sem FieldSpec correspondente não pode ser apagado na reescrita. */
    public function test_orphan_field_without_fieldspec_is_preserved_on_rewrite(): void
    {
        $current = [
            'Post' => [
                'descricao'      => 'um post',
                'tabela'         => 'posts',
                'campos'         => 'title, body',
                'relacoes'       => 'author: BelongsTo(Author)',
                'notas'          => '',
                'campo_orfao'    => 'valor que o host curou manualmente, campo saiu da spec atual',
            ],
        ];

        // fields ATUAL não inclui 'campo_orfao' -- simula host que removeu/renomeou o campo da spec
        $fieldSpecsAtuais = [
            new FieldSpec(name: 'gatilhos', type: FieldSpec::TYPE_STRING, llmInstructions: '...'),
        ];

        $this->writer->write($this->tmpFile, $current, $fieldSpecsAtuais);

        $relido = include $this->tmpFile;

        $this->assertArrayHasKey('campo_orfao', $relido['Post']);
        $this->assertSame('valor que o host curou manualmente, campo saiu da spec atual', $relido['Post']['campo_orfao']);
    }

    public function test_write_roundtrip_preserves_values(): void
    {
        $merged = [
            'Post' => [
                'descricao' => 'Um post de blog',
                'tabela'    => 'posts',
                'campos'    => 'title, body, author_id',
                'relacoes'  => 'author: BelongsTo(Author)',
                'notas'     => 'nota com "aspas" e vírgula, teste',
            ],
        ];

        $this->writer->write($this->tmpFile, $merged, []);
        $relido = include $this->tmpFile;

        $this->assertSame($merged, $relido);
    }

    public function test_write_preserves_leading_header_comment(): void
    {
        file_put_contents($this->tmpFile, "<?php\n\n// comentário de cabeçalho customizado\n\nreturn ['Old' => ['descricao' => 'x']];\n");

        $this->writer->write($this->tmpFile, ['Post' => ['descricao' => 'y']], []);

        $conteudo = file_get_contents($this->tmpFile);
        $this->assertStringContainsString('comentário de cabeçalho customizado', $conteudo);
    }
}
