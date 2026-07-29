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
        // referencia $query (passa a checagem semântica) mas é sintaticamente inválido — isola o
        // gate de sintaxe do gate semântico (novo), que roda ANTES.
        $resultado = $this->writer->write('Post', "return \$query->where('a', {{{", null);

        $this->assertSame('syntax_error', $resultado['status']);
        $this->assertFileDoesNotExist($this->writer->classPathFor('Post'));
    }

    // ── Sanitização de formato (achado do relatório real de produção) ─────────

    public function test_sanitize_strips_markdown_fence(): void
    {
        $bruto = "```php\nreturn \$query->where('x', 1);\n```";

        $this->assertSame("return \$query->where('x', 1);", $this->writer->sanitize($bruto));
    }

    public function test_sanitize_strips_reincluded_method_signature_and_braces(): void
    {
        $bruto = "public function apply(Builder \$query, mixed \$context): Builder\n{\n    return \$query;\n}";

        $this->assertSame('return $query;', $this->writer->sanitize($bruto));
    }

    public function test_sanitize_strips_leading_use_statement(): void
    {
        $bruto = "use App\\Models\\Coordinator;\n\nreturn \$query->where('x', 1);";

        $this->assertSame("return \$query->where('x', 1);", $this->writer->sanitize($bruto));
    }

    public function test_sanitize_leaves_already_clean_body_untouched(): void
    {
        $limpo = "if (\$context->isMaster()) {\n    return \$query;\n}\nreturn \$query->where('x', 1);";

        $this->assertSame($limpo, $this->writer->sanitize($limpo));
    }

    // ── Checagem semântica (o guarda-corpo real — php -l sozinho não pega isso) ─

    public function test_semantic_check_rejects_wrong_parameter_names_even_though_syntax_is_valid(): void
    {
        // $builder->whereRaw(...) é 100% válido sintaticamente — só está semanticamente errado
        // (o parâmetro real do apply() é $query, não $builder). Referencia $query também, pra
        // isolar especificamente o gate de "nome de variável errado" do gate de "nunca usa $query".
        $resultado = $this->writer->write('Post', 'if ($builder) { } return $query;', null);

        $this->assertSame('semantic_check_failed', $resultado['status']);
        $this->assertStringContainsString('builder', $resultado['message']);
        $this->assertFileDoesNotExist($this->writer->classPathFor('Post'));
    }

    public function test_semantic_check_rejects_body_that_never_mentions_query_specifically(): void
    {
        $resultado = $this->writer->write('Post', 'doSomethingUnrelated();', null);

        $this->assertSame('semantic_check_failed', $resultado['status']);
        $this->assertStringContainsString('$query', $resultado['message']);
    }

    public function test_semantic_check_rejects_direct_auth_call_ignoring_context(): void
    {
        $resultado = $this->writer->write(
            'Post',
            '$context = auth()->user(); return $query->where(\'coordinator_id\', $context->id);',
            null,
        );

        $this->assertSame('semantic_check_failed', $resultado['status']);
        $this->assertStringContainsString('context', $resultado['message']);
    }

    /**
     * Caso EXATO relatado em produção (model Acquisition, 13/45 gerações nesse formato): depois de
     * sanitizado, o corpo fica sintaticamente válido (php -l passaria) — mas ainda usa
     * $builder/$model e auth()->user() em vez de $query/$context. Prova que sanitizar sozinho
     * TROCARIA uma rejeição seguros (hoje, por acidente, via erro de sintaxe da cerca markdown) por
     * uma escrita silenciosamente errada — por isso a checagem semântica roda sempre, depois de
     * sanitizar, antes de aceitar.
     */
    public function test_reported_production_case_is_rejected_by_semantic_check_after_sanitization(): void
    {
        $bruto = <<<'RAW'
        ```php
        use App\Models\Coordinator;

        public function apply(Builder $builder, Model $model): void
        {
            $context = auth()->user();
            if (!$context) return;

            if ($context->hasRole(['Master', 'Gestor'])) {
                return;
            }

            $coordinator = $context->coordinator;

            if (!$coordinator) {
                $builder->whereRaw('1 = 0');
                return;
            }

            $builder->whereHas('demand', function ($q) use ($coordinator) {
                $q->whereHas('project', function ($q2) use ($coordinator) {
                    $q2->where('coordinator_id', $coordinator->id);
                });
            });
        }
        ```
        RAW;

        $resultado = $this->writer->write('Acquisition', $bruto, null);

        $this->assertSame('semantic_check_failed', $resultado['status']);
        $this->assertFileDoesNotExist($this->writer->classPathFor('Acquisition'));
        $this->assertStringContainsString('Acquisition', $resultado['message']);
        $this->assertSame(mb_substr($bruto, 0, 300), $resultado['raw_body_preview']);
    }

    public function test_validar_returns_null_for_clean_body(): void
    {
        $this->assertNull($this->writer->validar("return \$query->where('author_id', \$context->id);"));
    }

    public function test_validar_returns_error_message_without_writing_anything(): void
    {
        $erro = $this->writer->validar('$builder->whereRaw(\'1 = 0\'); return $builder;');

        $this->assertNotNull($erro);
        $this->assertFileDoesNotExist($this->writer->classPathFor('ValidacaoTemp'));
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
