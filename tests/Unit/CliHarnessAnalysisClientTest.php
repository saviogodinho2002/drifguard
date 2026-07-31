<?php

namespace Saviogodinho2002\DriftGuard\Tests\Unit;

use Saviogodinho2002\DriftGuard\Clients\CliHarnessAnalysisClient;
use Saviogodinho2002\DriftGuard\Tests\TestCase;

/**
 * Comando FAKE em vez do CLI real (`claude`/`gemini`/`opencode`) — determinístico, sem rede/custo
 * real, mesmo espírito de FakeAnalysisClient. `fakeCommand()` gera um wrapper shell executável
 * (`exec php <fixture> "$@"`) usado como `command:` — evita depender do parser de flags do próprio
 * `php` CLI (que não reconhece `-p`, sempre inserido pela classe antes de `extraArgs`).
 */
class CliHarnessAnalysisClientTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/driftguard_harness_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->tmpDir}/*") ?: []);
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    /** @param string $phpCode Corpo do script fake, recebe $argv normalmente. */
    private function fakeCommand(string $phpCode): string
    {
        $id       = uniqid();
        $fixture  = "{$this->tmpDir}/fixture_{$id}.php";
        $wrapper  = "{$this->tmpDir}/wrapper_{$id}.sh";

        file_put_contents($fixture, "<?php\n{$phpCode}");
        file_put_contents($wrapper, "#!/bin/sh\nexec php " . escapeshellarg($fixture) . " \"\$@\"\n");
        chmod($wrapper, 0755);

        return $wrapper;
    }

    private function toolsBasicos(): array
    {
        return [[
            'type'     => 'function',
            'function' => [
                'name'        => 'propose_update',
                'description' => 'Propõe a entrada de catálogo.',
                'parameters'  => ['type' => 'object', 'properties' => ['descricao' => ['type' => 'string']]],
            ],
        ]];
    }

    public function test_well_formed_single_json_response_maps_to_correct_tool_call(): void
    {
        $script = $this->fakeCommand(<<<'PHP'
        $resposta = ['tool' => 'propose_update', 'arguments' => ['descricao' => 'Uma descrição.']];
        echo json_encode(['result' => json_encode($resposta), 'total_cost_usd' => 0.0123]);
        PHP);

        $client = new CliHarnessAnalysisClient(command: $script, extraArgs: []);
        $resultado = $client->chat([['role' => 'user', 'content' => 'analise o model X']], $this->toolsBasicos());

        $this->assertCount(1, $resultado['tool_calls']);
        $this->assertSame('propose_update', $resultado['tool_calls'][0]['function']['name']);
        $this->assertSame(
            ['descricao' => 'Uma descrição.'],
            json_decode($resultado['tool_calls'][0]['function']['arguments'], true)
        );
    }

    public function test_response_wrapped_in_markdown_fence_is_still_parsed(): void
    {
        $script = $this->fakeCommand(<<<'PHP'
        $resposta = "```json\n" . json_encode(['tool' => 'ask_question', 'arguments' => ['question' => 'x?']]) . "\n```";
        echo json_encode(['result' => $resposta]);
        PHP);

        $client = new CliHarnessAnalysisClient(command: $script, extraArgs: []);
        $resultado = $client->chat([], $this->toolsBasicos());

        $this->assertCount(1, $resultado['tool_calls']);
        $this->assertSame('ask_question', $resultado['tool_calls'][0]['function']['name']);
    }

    /**
     * Gap real achado na revisão: instruo o harness a responder só com JSON, mas nada garante isso
     * em geral (CLIs sem o mesmo rigor de instrução do Claude Code CLI podem florear a resposta).
     * `parseChamada()` precisa achar o JSON válido mesmo com prosa antes/depois.
     */
    public function test_json_surrounded_by_prose_is_still_parsed(): void
    {
        $script = $this->fakeCommand(<<<'PHP'
        $resposta = "Claro! Aqui está minha análise:\n\n"
            . json_encode(['tool' => 'propose_update', 'arguments' => ['descricao' => 'com prosa']])
            . "\n\nEspero que ajude!";
        echo json_encode(['result' => $resposta]);
        PHP);

        $client = new CliHarnessAnalysisClient(command: $script, extraArgs: []);
        $resultado = $client->chat([], $this->toolsBasicos());

        $this->assertCount(1, $resultado['tool_calls']);
        $this->assertSame('propose_update', $resultado['tool_calls'][0]['function']['name']);
        $this->assertSame(
            ['descricao' => 'com prosa'],
            json_decode($resultado['tool_calls'][0]['function']['arguments'], true)
        );
    }

    public function test_non_json_output_degrades_to_empty_tool_calls_without_exception(): void
    {
        $script = $this->fakeCommand("echo 'isso não é json nenhum';");

        $client = new CliHarnessAnalysisClient(command: $script, extraArgs: []);
        $resultado = $client->chat([], $this->toolsBasicos());

        $this->assertSame(['content' => null, 'tool_calls' => []], $resultado);
    }

    public function test_result_field_missing_degrades_to_empty_tool_calls(): void
    {
        $script = $this->fakeCommand("echo json_encode(['algo_diferente' => 'x']);");

        $client = new CliHarnessAnalysisClient(command: $script, extraArgs: []);
        $resultado = $client->chat([], $this->toolsBasicos());

        $this->assertSame(['content' => null, 'tool_calls' => []], $resultado);
    }

    public function test_nonexistent_command_degrades_safely(): void
    {
        $client = new CliHarnessAnalysisClient(command: '/caminho/que/definitivamente/nao/existe/xyz', extraArgs: []);
        $resultado = $client->chat([], $this->toolsBasicos());

        $this->assertSame(['content' => null, 'tool_calls' => []], $resultado);
    }

    public function test_timeout_degrades_safely(): void
    {
        $script = $this->fakeCommand('sleep(3);');

        $client = new CliHarnessAnalysisClient(command: $script, extraArgs: [], timeoutSeconds: 1);
        $resultado = $client->chat([], $this->toolsBasicos());

        $this->assertSame(['content' => null, 'tool_calls' => []], $resultado);
    }

    public function test_json_stream_format_finds_step_finish_event(): void
    {
        $script = $this->fakeCommand(<<<'PHP'
        $resposta = json_encode(['tool' => 'propose_update', 'arguments' => ['descricao' => 'y']]);
        echo json_encode(['type' => 'init']) . "\n";
        echo json_encode(['type' => 'message', 'text' => 'explorando...']) . "\n";
        echo json_encode(['type' => 'step_finish', 'text' => $resposta, 'cost' => 0.5]) . "\n";
        PHP);

        $client = new CliHarnessAnalysisClient(
            command: $script,
            extraArgs: [],
            responseFormat: 'json_stream',
            resultField: 'text',
            costField: 'cost',
        );
        $resultado = $client->chat([], $this->toolsBasicos());

        $this->assertCount(1, $resultado['tool_calls']);
        $this->assertSame('propose_update', $resultado['tool_calls'][0]['function']['name']);
    }

    public function test_plain_text_format_parses_stdout_directly(): void
    {
        $script = $this->fakeCommand(<<<'PHP'
        echo json_encode(['tool' => 'propose_update', 'arguments' => ['descricao' => 'z']]);
        PHP);

        $client = new CliHarnessAnalysisClient(
            command: $script,
            extraArgs: [],
            responseFormat: 'plain_text',
            costField: null,
        );
        $resultado = $client->chat([], $this->toolsBasicos());

        $this->assertCount(1, $resultado['tool_calls']);
        $this->assertSame('propose_update', $resultado['tool_calls'][0]['function']['name']);
    }

    /** allowedBasePath/harnessTools precisam virar argumentos de verdade no processo, não só na config. */
    public function test_allowed_base_path_and_harness_tools_are_passed_as_real_arguments(): void
    {
        // fora de $this->tmpDir de propósito: com readonlyLock (default true), tmpDir fica travado
        // contra escrita durante a chamada — o script fake precisa gravar o argv capturado em
        // outro lugar, senão a própria instrumentação do teste seria bloqueada pelo lock.
        $argvCapturado = sys_get_temp_dir() . '/driftguard_argv_capturado_' . uniqid() . '.json';
        $script = $this->fakeCommand(<<<PHP
        file_put_contents('{$argvCapturado}', json_encode(\$argv));
        echo json_encode(['result' => json_encode(['tool' => 'propose_update', 'arguments' => []])]);
        PHP);

        // allowedBasePath vira o cwd real do processo (Process::path()) — precisa ser um diretório
        // que exista de verdade, não um placeholder.
        $client = new CliHarnessAnalysisClient(
            command: $script,
            extraArgs: [],
            allowedBasePath: $this->tmpDir,
            harnessTools: ['Read', 'Grep'],
        );
        $client->chat([], $this->toolsBasicos());

        $argv = json_decode(file_get_contents($argvCapturado), true);

        $this->assertContains('--add-dir', $argv);
        $this->assertContains($this->tmpDir, $argv);
        $this->assertContains('--allowedTools', $argv);
        $this->assertContains('Read,Grep', $argv);

        @unlink($argvCapturado);
    }

    /**
     * Piso de segurança (achado numa revisão): harness_tools é 100% configurável pelo host, sem
     * guarda nenhuma antes disso — nada impedia 'harness_tools' => ['Read', 'Bash'] de virar
     * --allowedTools "Read,Bash" de verdade. DENYLIST_TOOLS filtra isso sempre, mesmo configurado
     * explicitamente.
     */
    public function test_dangerous_tools_are_always_filtered_out_regardless_of_config(): void
    {
        $argvCapturado = sys_get_temp_dir() . '/driftguard_argv_denylist_' . uniqid() . '.json';
        $script = $this->fakeCommand(<<<PHP
        file_put_contents('{$argvCapturado}', json_encode(\$argv));
        echo json_encode(['result' => json_encode(['tool' => 'propose_update', 'arguments' => []])]);
        PHP);

        $client = new CliHarnessAnalysisClient(
            command: $script,
            extraArgs: [],
            allowedBasePath: $this->tmpDir,
            harnessTools: ['Read', 'Bash', 'Grep', 'Write', 'Edit', 'NotebookEdit'],
        );
        $client->chat([], $this->toolsBasicos());

        $argv = json_decode(file_get_contents($argvCapturado), true);
        $indiceFlag = array_search('--allowedTools', $argv, true);
        $listaTools = $argv[$indiceFlag + 1];

        $this->assertStringContainsString('Read', $listaTools);
        $this->assertStringContainsString('Grep', $listaTools);
        $this->assertStringNotContainsString('Bash', $listaTools);
        $this->assertStringNotContainsString('Write', $listaTools);
        $this->assertStringNotContainsString('Edit', $listaTools);
        $this->assertStringNotContainsString('NotebookEdit', $listaTools);

        @unlink($argvCapturado);
    }

    /**
     * Camada principal de defesa (achado na mesma revisão): o diretório precisa estar REALMENTE
     * travado contra escrita durante a chamada, não só ter as tools "certas" descritas — testa que
     * uma escrita de verdade DENTRO do diretório travado falha durante a execução do subprocesso, e
     * volta a funcionar depois que `chat()` retorna.
     */
    public function test_allowed_base_path_is_locked_during_the_call_and_unlocked_after(): void
    {
        $resultadoEscrita = sys_get_temp_dir() . '/driftguard_resultado_escrita_' . uniqid() . '.txt';
        $script = $this->fakeCommand(<<<PHP
        \$conseguiu = @file_put_contents(__DIR__ . '/arquivo_durante_lock.txt', 'nao deveria conseguir');
        file_put_contents('{$resultadoEscrita}', \$conseguiu === false ? 'BLOQUEADO' : 'ESCREVEU');
        echo json_encode(['result' => json_encode(['tool' => 'propose_update', 'arguments' => []])]);
        PHP);

        $client = new CliHarnessAnalysisClient(
            command: $script,
            extraArgs: [],
            allowedBasePath: $this->tmpDir,
        );
        $client->chat([], $this->toolsBasicos());

        $this->assertSame('BLOQUEADO', file_get_contents($resultadoEscrita), 'escrita dentro do allowedBasePath deveria ter sido bloqueada DURANTE a chamada');
        $this->assertNotFalse(@file_put_contents("{$this->tmpDir}/depois.txt", 'ok'), 'diretório deveria estar destravado DEPOIS da chamada');

        @unlink($resultadoEscrita);
    }
}
