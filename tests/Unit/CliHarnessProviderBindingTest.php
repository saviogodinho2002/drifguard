<?php

namespace Saviogodinho2002\DriftGuard\Tests\Unit;

use Saviogodinho2002\DriftGuard\Contracts\AnalysisClient;
use Saviogodinho2002\DriftGuard\Tests\TestCase;

/**
 * Testa a resolução REAL de `AnalysisClient::class` via `DriftGuardServiceProvider` (não troca o
 * binding por um fake, ao contrário dos testes de command) — é a única forma de cobrir
 * `makeCliHarnessClient()`, que é privado.
 */
class CliHarnessProviderBindingTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/driftguard_provider_binding_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->tmpDir}/*") ?: []);
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    private function fakeCommandCapturandoArgv(string $argvFile): string
    {
        $fixture = "{$this->tmpDir}/fixture.php";
        $wrapper = "{$this->tmpDir}/wrapper.sh";

        file_put_contents($fixture, "<?php\n"
            . "file_put_contents(" . var_export($argvFile, true) . ", json_encode(\$argv));\n"
            . "echo json_encode(['result' => json_encode(['tool' => 'propose_update', 'arguments' => []])]);\n");
        file_put_contents($wrapper, "#!/bin/sh\nexec php " . escapeshellarg($fixture) . " \"\$@\"\n");
        chmod($wrapper, 0755);

        return $wrapper;
    }

    /**
     * Bug real corrigido: o preset 'gemini' seta dir_flag/tools_flag como null DE PROPÓSITO (sem
     * flag de restrição confirmada na doc pra essa CLI) — `makeCliHarnessClient()` usava `??` pra
     * ler cada chave, o que apagava esse null e reintroduzia --add-dir/--allowedTools (as flags do
     * Claude Code CLI) numa chamada que seria pro `gemini`. Testa a resolução real via container,
     * não a classe isolada, porque o bug morava no merge de preset+override do ServiceProvider.
     */
    public function test_gemini_preset_never_passes_claude_specific_flags(): void
    {
        $argvFile = "{$this->tmpDir}/argv.json";
        $comandoFake = $this->fakeCommandCapturandoArgv($argvFile);

        config(['driftguard.llm.driver' => 'cli_harness']);
        config(['driftguard.llm.cli_harness_preset' => 'gemini']);
        config(['driftguard.llm.cli_harness' => [
            'command'    => $comandoFake,
            'extra_args' => [], // sem --output-format json (preset gemini já reflete isso, mas garante no teste)
        ]]);

        $client = app(AnalysisClient::class);
        $client->chat([], []);

        $argv = json_decode(file_get_contents($argvFile), true);

        $this->assertNotContains('--add-dir', $argv, 'preset gemini não define dir_flag — nunca deveria herdar --add-dir do Claude Code CLI');
        $this->assertNotContains('--allowedTools', $argv, 'preset gemini não define tools_flag — nunca deveria herdar --allowedTools do Claude Code CLI');
    }

    public function test_claude_preset_still_passes_its_own_flags_by_default(): void
    {
        // fora de $this->tmpDir de propósito: allowed_base_path === tmpDir fica travado contra
        // escrita durante a chamada (readonly_lock, default true) — o script fake precisa gravar
        // o argv capturado em outro lugar.
        $argvFile = sys_get_temp_dir() . '/driftguard_argv_' . uniqid() . '.json';
        $comandoFake = $this->fakeCommandCapturandoArgv($argvFile);

        config(['driftguard.llm.driver' => 'cli_harness']);
        config(['driftguard.llm.cli_harness_preset' => 'claude']);
        config(['driftguard.llm.cli_harness' => ['command' => $comandoFake, 'extra_args' => []]]);
        // allowedBasePath vira o cwd real do processo (Process::path()) — precisa existir de verdade.
        config(['driftguard.allowed_base_path' => $this->tmpDir]);

        $client = app(AnalysisClient::class);
        $client->chat([], []);

        $argv = json_decode(file_get_contents($argvFile), true);

        $this->assertContains('--add-dir', $argv);
        $this->assertContains($this->tmpDir, $argv);
        $this->assertContains('--allowedTools', $argv);

        @unlink($argvFile);
    }
}
