<?php

namespace Saviogodinho2002\DriftGuard\Clients;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Saviogodinho2002\DriftGuard\Contracts\AnalysisClient;
use Saviogodinho2002\DriftGuard\Support\BraceMatcher;
use Saviogodinho2002\DriftGuard\Support\ReadOnlyLock;

/**
 * Provedor alternativo de análise: em vez de uma API stateless (OpenRouter), invoca um agente-CLI
 * (Claude Code, Gemini CLI, opencode) como subprocesso — o harness explora o código sozinho (Read/
 * Grep próprios, restritos a `allowedBasePath`) em vez de receber um snippet pré-empacotado. A
 * ponte com o contrato stateless de `AnalysisClient` é feita aqui: 1 chamada de `chat()` = 1
 * invocação não-interativa do CLI, cuja resposta final é interpretada como exatamente 1 tool call.
 *
 * Segurança durante a exploração, em 3 camadas independentes (nenhuma sozinha é suficiente):
 * 1. `ReadOnlyLock` (`readonlyLock`) — trava o diretório contra escrita no sistema de arquivos
 *    antes de rodar o subprocesso. Funciona igual pros 3 presets, independente de a CLI alvo ter
 *    flag própria de restrição de tool. Não funciona em Windows (ver `ReadOnlyLock`).
 * 2. `dirFlag`/`toolsFlag` — allowlist de tool da própria CLI alvo, quando ela suporta (hoje só o
 *    preset `claude` tem `tools_flag` confirmado; `gemini`/`opencode` não têm equivalente
 *    documentado).
 * 3. `DENYLIST_TOOLS` — piso de código: `Bash`/`Write`/`Edit`/`NotebookEdit` nunca entram na
 *    allowlist passada pra CLI, mesmo que configurado explicitamente pelo host.
 *
 * O preset `claude` (Claude Code CLI) é o único validado com uma chamada real de ponta a ponta
 * contra um model de produção. Presets `gemini`/`opencode` seguem a documentação oficial de cada
 * CLI — ajuste `cli_harness` se a versão instalada usar uma flag diferente.
 */
class CliHarnessAnalysisClient implements AnalysisClient
{
    /** @var string[] Tools que mutam estado — nunca entram na allowlist, mesmo configuradas explicitamente. */
    private const DENYLIST_TOOLS = ['Bash', 'Write', 'Edit', 'NotebookEdit'];

    public function __construct(
        private readonly string $command = 'claude',
        /** @var string[] */
        private readonly array $extraArgs = ['--output-format', 'json'],
        /** 'single_json' (Claude Code CLI) | 'json_stream' (opencode) | 'plain_text' (fallback / Gemini CLI hoje, ver gemini-cli#9009). */
        private readonly string $responseFormat = 'single_json',
        /** Campo com o texto final — em single_json é uma chave do objeto; em json_stream, uma chave do evento de finalização. Null em plain_text. */
        private readonly ?string $resultField = 'result',
        /** Campo de custo, mesma lógica de resultField. Null = sem rastreio de custo disponível (ex: Gemini CLI hoje). */
        private readonly ?string $costField = 'total_cost_usd',
        private readonly int $timeoutSeconds = 300,
        /** Diretório que o harness pode explorar sozinho — mesmo princípio de allowed_base_path do request_file. */
        private readonly ?string $allowedBasePath = null,
        /** @var string[] Ferramentas que o próprio CLI pode usar pra explorar — DENYLIST_TOOLS é removida daqui sempre, mesmo se configurado. */
        private array $harnessTools = ['Read', 'Grep', 'Glob'],
        /** Flag de restrição de diretório do CLI alvo (ex: '--add-dir' pro Claude Code CLI). Null = CLI não documenta uma. */
        private readonly ?string $dirFlag = '--add-dir',
        /** Flag de allowlist de ferramentas do CLI alvo (ex: '--allowedTools'). Null = CLI não documenta uma. */
        private readonly ?string $toolsFlag = '--allowedTools',
        /** Trava o diretório contra escrita no sistema de arquivos durante a chamada (camada 1 — ver docblock da classe). */
        private readonly bool $readonlyLock = true,
    ) {
        $pedidas = $this->harnessTools;
        $this->harnessTools = array_values(array_diff($this->harnessTools, self::DENYLIST_TOOLS));

        if (empty($this->harnessTools) && !empty($pedidas)) {
            Log::warning('[driftguard] CliHarnessAnalysisClient: todas as tools pedidas foram bloqueadas por segurança (Bash/Write/Edit/NotebookEdit nunca são permitidas)', [
                'pedidas' => $pedidas,
            ]);
        }
    }

    public function chat(array $messages, array $tools): array
    {
        $prompt = $this->buildPrompt($messages, $tools);
        $args   = [$this->command, '-p', $prompt, ...$this->extraArgs];

        if ($this->dirFlag !== null && $this->allowedBasePath !== null) {
            $args[] = $this->dirFlag;
            $args[] = $this->allowedBasePath;
        }
        if ($this->toolsFlag !== null && !empty($this->harnessTools)) {
            $args[] = $this->toolsFlag;
            $args[] = implode(',', $this->harnessTools);
        }

        $modosOriginais = [];
        if ($this->readonlyLock && $this->allowedBasePath !== null) {
            $modosOriginais = (new ReadOnlyLock())->travar($this->allowedBasePath);
        }

        try {
            $processo = Process::timeout($this->timeoutSeconds);
            if ($this->allowedBasePath !== null) {
                $processo = $processo->path($this->allowedBasePath);
            }
            $resultado = $processo->run($args);
        } catch (\Throwable $e) {
            return ['content' => null, 'tool_calls' => []];
        } finally {
            if (!empty($modosOriginais)) {
                (new ReadOnlyLock())->destravar($modosOriginais);
            }
        }

        if (!$resultado->successful()) {
            return ['content' => null, 'tool_calls' => []];
        }

        ['texto' => $texto, 'custo' => $custo] = $this->extrairTextoECusto($resultado->output());

        if ($custo !== null) {
            Log::info('[driftguard] CliHarnessAnalysisClient: custo da chamada', [
                'command' => $this->command,
                'custo_usd' => $custo,
            ]);
        }

        if ($texto === null) {
            return ['content' => null, 'tool_calls' => []];
        }

        $chamada = $this->parseChamada($texto);
        if ($chamada === null) {
            return ['content' => null, 'tool_calls' => []];
        }

        return [
            'content'    => null,
            'tool_calls' => [[
                'id'       => 'harness-1',
                'function' => [
                    'name'      => $chamada['tool'],
                    'arguments' => json_encode($chamada['arguments'] ?? []),
                ],
            ]],
        ];
    }

    /** @param array<int, array{role: string, content: mixed}> $messages */
    private function buildPrompt(array $messages, array $tools): string
    {
        $partes = [];
        foreach ($messages as $m) {
            $conteudo = is_string($m['content'] ?? null) ? $m['content'] : json_encode($m['content'] ?? null);
            $partes[] = strtoupper($m['role'] ?? '') . ":\n" . $conteudo;
        }

        $partes[] = "AÇÕES DISPONÍVEIS (escolha exatamente 1):\n" . $this->descreverTools($tools);

        $partes[] = 'Responda com EXATAMENTE 1 JSON object, sem cerca markdown, sem texto antes ou '
            . 'depois: {"tool": "<nome da ação>", "arguments": {...}}';

        return implode("\n\n", $partes);
    }

    /** @param array<int, array{type: string, function: array}> $tools */
    private function descreverTools(array $tools): string
    {
        $linhas = [];
        foreach ($tools as $tool) {
            $fn            = $tool['function'] ?? [];
            $nome          = $fn['name'] ?? '';
            $descricao     = $fn['description'] ?? '';
            $propriedades  = array_keys($fn['parameters']['properties'] ?? []);
            $linhas[]      = "- {$nome}: {$descricao} (arguments: " . implode(', ', $propriedades) . ')';
        }
        return implode("\n", $linhas);
    }

    /** @return array{texto: ?string, custo: ?float} */
    private function extrairTextoECusto(string $saida): array
    {
        return match ($this->responseFormat) {
            'single_json' => $this->extrairDeSingleJson($saida),
            'json_stream' => $this->extrairDeJsonStream($saida),
            default       => ['texto' => trim($saida) === '' ? null : $saida, 'custo' => null],
        };
    }

    /** @return array{texto: ?string, custo: ?float} */
    private function extrairDeSingleJson(string $saida): array
    {
        $dados = json_decode($saida, true);
        if (!is_array($dados)) {
            return ['texto' => null, 'custo' => null];
        }

        return [
            'texto' => $this->resultField !== null ? ($dados[$this->resultField] ?? null) : null,
            'custo' => $this->costField !== null ? ($dados[$this->costField] ?? null) : null,
        ];
    }

    /** @return array{texto: ?string, custo: ?float} */
    private function extrairDeJsonStream(string $saida): array
    {
        $linhas = array_filter(explode("\n", $saida), fn($l) => trim($l) !== '');

        foreach (array_reverse($linhas) as $linha) {
            $evento = json_decode($linha, true);
            if (!is_array($evento)) {
                continue;
            }
            $tipo = $evento['type'] ?? null;
            if (in_array($tipo, ['step_finish', 'step-finish'], true)) {
                return [
                    'texto' => $this->resultField !== null ? ($evento[$this->resultField] ?? null) : null,
                    'custo' => $this->costField !== null ? ($evento[$this->costField] ?? null) : null,
                ];
            }
        }

        return ['texto' => null, 'custo' => null];
    }

    /**
     * Tolera prosa antes/depois do JSON (ex: "Aqui está: {...}") — instruo o harness a responder
     * só com JSON, mas nada garante isso em geral, principalmente pra CLIs sem o mesmo rigor de
     * instrução observado no Claude Code CLI. Acha o primeiro `{` e usa o mesmo casamento de chaves
     * balanceado já usado em `ModelDiscovery`/`ScopeClassWriter` em vez de exigir que o texto
     * INTEIRO seja JSON puro.
     *
     * @return array{tool: string, arguments: array}|null
     */
    private function parseChamada(string $texto): ?array
    {
        $limpo = $this->removerCercaMarkdown(trim($texto));

        $posicaoAbertura = strpos($limpo, '{');
        if ($posicaoAbertura === false) {
            return null;
        }

        $posicaoFechamento = BraceMatcher::fechamentoDe($limpo, $posicaoAbertura);
        if ($posicaoFechamento === null) {
            return null;
        }

        $bloco = substr($limpo, $posicaoAbertura, $posicaoFechamento - $posicaoAbertura + 1);
        $dados = json_decode($bloco, true);

        if (!is_array($dados) || !isset($dados['tool'])) {
            return null;
        }

        return $dados;
    }

    private function removerCercaMarkdown(string $texto): string
    {
        if (preg_match('/^```(?:json)?\s*\n(.*)\n```$/s', $texto, $m)) {
            return trim($m[1]);
        }
        return $texto;
    }
}
