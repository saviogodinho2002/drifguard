<?php

namespace Saviogodinho2002\DriftGuard\Tests\Console;

use Illuminate\Support\Facades\Artisan;
use Saviogodinho2002\DriftGuard\Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    private string $tmpStorage;
    private string $tmpOutputConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpStorage      = sys_get_temp_dir() . '/driftguard_doctor_storage_' . uniqid();
        $this->tmpOutputConfig = sys_get_temp_dir() . '/driftguard_doctor_models_' . uniqid() . '.php';

        config(['driftguard.models_path'  => $this->fixturesPath('Models')]);
        config(['driftguard.model_namespace' => 'Saviogodinho2002\\DriftGuard\\Tests\\Fixtures\\Models']);
        config(['driftguard.output_path'  => $this->tmpOutputConfig]);
        config(['driftguard.storage_path' => $this->tmpStorage]);
        config(['driftguard.fields'       => []]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->tmpStorage}/*") ?: []);
        @rmdir($this->tmpStorage);
        parent::tearDown();
    }

    public function test_passes_with_valid_default_config(): void
    {
        $exitCode = Artisan::call('driftguard:doctor');

        $this->assertSame(0, $exitCode);
    }

    public function test_json_output_is_valid_and_decodable(): void
    {
        $exitCode = Artisan::call('driftguard:doctor', ['--json' => true]);
        $saida    = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($saida['ok']);
        $this->assertNotEmpty($saida['checks']);
        $this->assertArrayHasKey('status', $saida['checks'][0]);
        $this->assertArrayHasKey('item', $saida['checks'][0]);
        $this->assertArrayHasKey('detalhe', $saida['checks'][0]);
    }

    public function test_json_output_reflects_failure(): void
    {
        config(['driftguard.models_path' => '/caminho/que/definitivamente/nao/existe']);

        $exitCode = Artisan::call('driftguard:doctor', ['--json' => true]);
        $saida    = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($saida['ok']);
    }

    public function test_fails_on_malformed_field(): void
    {
        config(['driftguard.fields' => [
            ['name' => 'x', 'type' => 'tipo_invalido', 'llm_instructions' => '...'],
        ]]);

        $exitCode = Artisan::call('driftguard:doctor');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('inválido', Artisan::output());
    }

    /**
     * Inspirado no docudoodle (detecta doc órfão quando o source some) — mas aqui só REPORTA
     * (WARN, nunca FAIL): config/models.php é dado curado à mão, apagar sozinho quebraria a
     * garantia já documentada de "campo que sai da spec é preservado, nunca apagado silenciosamente".
     */
    public function test_warns_on_orphaned_catalog_entry_whose_model_no_longer_exists(): void
    {
        file_put_contents($this->tmpOutputConfig, "<?php\nreturn ['ModeloFantasma' => ['descricao' => 'x'], 'Post' => ['descricao' => 'y']];\n");

        $exitCode = Artisan::call('driftguard:doctor');
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode, 'órfão é WARN, nunca deveria falhar o exit code');
        $this->assertStringContainsString('ModeloFantasma', $output);
        $this->assertStringContainsString('revise manualmente', $output);
    }

    public function test_no_orphan_warning_when_every_catalogued_model_still_exists(): void
    {
        file_put_contents($this->tmpOutputConfig, "<?php\nreturn ['Post' => ['descricao' => 'x'], 'Author' => ['descricao' => 'y']];\n");

        $exitCode = Artisan::call('driftguard:doctor');

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('revise manualmente', Artisan::output());
    }

    public function test_fails_on_nonexistent_models_path(): void
    {
        config(['driftguard.models_path' => '/caminho/que/definitivamente/nao/existe']);

        $exitCode = Artisan::call('driftguard:doctor');

        $this->assertSame(1, $exitCode);
    }

    public function test_fails_on_scope_class_field_with_invalid_namespace(): void
    {
        $scopePath = sys_get_temp_dir() . '/driftguard_doctor_scopes_' . uniqid();

        config(['driftguard.fields' => [
            ['name' => 'escopo', 'type' => 'scope_class', 'llm_instructions' => '...'],
        ]]);
        config(['driftguard.scope_class_namespace' => 'nao_e_namespace_valido']);
        config(['driftguard.scope_class_path' => $scopePath]);

        $exitCode = Artisan::call('driftguard:doctor');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('namespace inválido', Artisan::output());

        @rmdir($scopePath);
    }

    /**
     * Achado ao investigar a colisão de scope_class no mesmo model: nome de field duplicado
     * sobrescreve silenciosamente no schema de tool-calling — pega isso ANTES de analyze/apply.
     */
    public function test_fails_on_duplicate_field_name(): void
    {
        config(['driftguard.fields' => [
            ['name' => 'escopo_projeto', 'type' => 'string', 'llm_instructions' => 'a'],
            ['name' => 'escopo_projeto', 'type' => 'string', 'llm_instructions' => 'b'],
        ]]);

        $exitCode = Artisan::call('driftguard:doctor');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('duplicado', Artisan::output());
    }
}
