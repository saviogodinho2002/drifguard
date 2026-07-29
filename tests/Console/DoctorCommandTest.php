<?php

namespace Saviogodinho2002\Drifguard\Tests\Console;

use Illuminate\Support\Facades\Artisan;
use Saviogodinho2002\Drifguard\Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    private string $tmpStorage;
    private string $tmpOutputConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpStorage      = sys_get_temp_dir() . '/drifguard_doctor_storage_' . uniqid();
        $this->tmpOutputConfig = sys_get_temp_dir() . '/drifguard_doctor_models_' . uniqid() . '.php';

        config(['drifguard.models_path'  => $this->fixturesPath('Models')]);
        config(['drifguard.output_path'  => $this->tmpOutputConfig]);
        config(['drifguard.storage_path' => $this->tmpStorage]);
        config(['drifguard.fields'       => []]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->tmpStorage}/*") ?: []);
        @rmdir($this->tmpStorage);
        parent::tearDown();
    }

    public function test_passes_with_valid_default_config(): void
    {
        $exitCode = Artisan::call('drifguard:doctor');

        $this->assertSame(0, $exitCode);
    }

    public function test_fails_on_malformed_field(): void
    {
        config(['drifguard.fields' => [
            ['name' => 'x', 'type' => 'tipo_invalido', 'llm_instructions' => '...'],
        ]]);

        $exitCode = Artisan::call('drifguard:doctor');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('inválido', Artisan::output());
    }

    public function test_fails_on_nonexistent_models_path(): void
    {
        config(['drifguard.models_path' => '/caminho/que/definitivamente/nao/existe']);

        $exitCode = Artisan::call('drifguard:doctor');

        $this->assertSame(1, $exitCode);
    }

    public function test_fails_on_scope_class_field_with_invalid_namespace(): void
    {
        $scopePath = sys_get_temp_dir() . '/drifguard_doctor_scopes_' . uniqid();

        config(['drifguard.fields' => [
            ['name' => 'escopo', 'type' => 'scope_class', 'llm_instructions' => '...'],
        ]]);
        config(['drifguard.scope_class_namespace' => 'nao_e_namespace_valido']);
        config(['drifguard.scope_class_path' => $scopePath]);

        $exitCode = Artisan::call('drifguard:doctor');

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
        config(['drifguard.fields' => [
            ['name' => 'escopo_projeto', 'type' => 'string', 'llm_instructions' => 'a'],
            ['name' => 'escopo_projeto', 'type' => 'string', 'llm_instructions' => 'b'],
        ]]);

        $exitCode = Artisan::call('drifguard:doctor');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('duplicado', Artisan::output());
    }
}
