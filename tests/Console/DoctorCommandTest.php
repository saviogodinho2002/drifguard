<?php

namespace Saviogodinho2002\DriftGuard\Tests\Console;

use Illuminate\Support\Facades\Artisan;
use Saviogodinho2002\DriftGuard\Console\DoctorCommand;
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

    /**
     * Achados de uma revisão de segurança: modo `cli_harness` ganha 4 avisos novos (nunca FAIL,
     * são só sinalização) sobre as camadas de defesa durante a exploração do harness.
     */
    public function test_no_harness_warnings_when_driver_is_openrouter(): void
    {
        $exitCode = Artisan::call('driftguard:doctor');
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('readonly_lock', $output);
        $this->assertStringNotContainsString('cli_harness_preset', $output);
    }

    public function test_warns_when_readonly_lock_disabled_on_non_windows(): void
    {
        config(['driftguard.llm.driver' => 'cli_harness']);
        config(['driftguard.llm.readonly_lock' => false]);

        $exitCode = Artisan::call('driftguard:doctor');
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode, 'aviso é WARN, nunca deveria falhar o exit code');
        $this->assertStringContainsString('está desligado', $output);
    }

    public function test_warns_about_readonly_lock_being_unavailable_on_windows(): void
    {
        config(['driftguard.llm.driver' => 'cli_harness']);

        $this->app->bind(DoctorCommand::class, fn() => new class extends DoctorCommand {
            protected function osFamily(): string
            {
                return 'Windows';
            }
        });

        $exitCode = Artisan::call('driftguard:doctor');
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('não funciona no Windows', $output);
    }

    public function test_warns_when_preset_has_no_tools_flag(): void
    {
        config(['driftguard.llm.driver' => 'cli_harness']);
        config(['driftguard.llm.cli_harness_preset' => 'gemini']);

        $exitCode = Artisan::call('driftguard:doctor');
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString("preset 'gemini' não tem allowlist", $output);
    }

    public function test_no_tools_flag_warning_for_claude_preset(): void
    {
        config(['driftguard.llm.driver' => 'cli_harness']);
        config(['driftguard.llm.cli_harness_preset' => 'claude']);

        $exitCode = Artisan::call('driftguard:doctor');
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('não tem allowlist', $output);
    }

    public function test_warns_when_allowed_base_path_is_the_whole_project(): void
    {
        config(['driftguard.llm.driver' => 'cli_harness']);
        config(['driftguard.allowed_base_path' => base_path()]);

        $exitCode = Artisan::call('driftguard:doctor');
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('projeto inteiro', $output);
    }
}
