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
 * contra um model de produção. Os shapes de `gemini`/`opencode` foram confirmados lendo o
 * código-fonte de cada CLI (tipos/schema reais, não a documentação em prosa) — `gemini` também tem
 * 1 amostra real (chamada autenticada) batendo com o schema encontrado no fonte, mas não
 * reproduzível neste ambiente (sem credencial persistente); `opencode` não foi instalado/rodado ao
 * vivo. Ajuste `cli_harness` se a versão instalada usar um shape diferente.
 */
class CliHarnessAnalysisClient implements AnalysisClient
{
    /** @var string[] Tools que mutam estado — nunca entram na allowlist, mesmo configuradas explicitamente. */
    private const DENYLIST_TOOLS = ['Bash', 'Write', 'Edit', 'NotebookEdit'];

    public function __construct(
        private readonly string $command = 'claude',
        /** @var string[] */
        private readonly array $extraArgs = ['--output-format', 'json'],
        /** 'single_json' (Claude Code CLI, Gemini CLI) | 'json_stream' (opencode) | 'plain_text' (fallback). */
        private readonly string $responseFormat = 'single_json',
        /**
         * Caminho (dot-path, ex: 'response', ou 'part.text' pra json_stream) até o texto final. Em
         * single_json, resolvido contra o objeto decodificado inteiro. Em json_stream, resolvido
         * contra o evento tipo "text" (não o de finalização de passo — são eventos DIFERENTES no
         * stream real do opencode). Null em plain_text (usa o stdout inteiro).
         */
        private readonly ?string $resultField = 'result',
        /**
         * Caminho (dot-path, ex: 'total_cost_usd', ou 'part.cost' pra json_stream) até o custo em
         * USD. Em json_stream, resolvido contra CADA evento de finalização de passo
         * ("step_finish"/"step-finish") e SOMADO entre eles (pode haver mais de 1 passo de LLM numa
         * só resposta). Suporta `*` como segmento curinga pra somar através de um dicionário de
         * chave dinâmica, mesma mecânica de `promptTokensField`/`completionTokensField` abaixo — na
         * prática nenhum preset hoje reporta custo por chave dinâmica, só tokens (ver Gemini). Null
         * = sem rastreio de custo disponível.
         */
        private readonly ?string $costField = 'total_cost_usd',
        /** Caminho (dot-path, mesmo suporte a `*`) até tokens de entrada/prompt. Null = indisponível. */
        private readonly ?string $promptTokensField = null,
        /** Caminho (dot-path, mesmo suporte a `*`) até tokens de saída/completion. Null = indisponível. */
        private readonly ?string $completionTokensField = null,
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
            return ['content' => null, 'tool_calls' => [], 'usage' => $this->usageVazio()];
        } finally {
            if (!empty($modosOriginais)) {
                (new ReadOnlyLock())->destravar($modosOriginais);
            }
        }

        if (!$resultado->successful()) {
            return ['content' => null, 'tool_calls' => [], 'usage' => $this->usageVazio()];
        }

        $extraido = $this->extrairTextoECusto($resultado->output());
        $texto    = $extraido['texto'];
        $custo    = $extraido['custo'];
        $usage    = [
            'cost_usd'          => $custo,
            'prompt_tokens'     => $extraido['prompt_tokens'],
            'completion_tokens' => $extraido['completion_tokens'],
        ];

        if ($custo !== null) {
            Log::info('[driftguard] CliHarnessAnalysisClient: custo da chamada', [
                'command' => $this->command,
                'custo_usd' => $custo,
            ]);
        }

        if ($texto === null) {
            return ['content' => null, 'tool_calls' => [], 'usage' => $usage];
        }

        $chamada = $this->parseChamada($texto);
        if ($chamada === null) {
            return ['content' => null, 'tool_calls' => [], 'usage' => $usage];
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
            'usage' => $usage,
        ];
    }

    /** @return array{cost_usd: ?float, prompt_tokens: ?int, completion_tokens: ?int} */
    private function usageVazio(): array
    {
        return ['cost_usd' => null, 'prompt_tokens' => null, 'completion_tokens' => null];
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

    /** @return array{texto: ?string, custo: ?float, prompt_tokens: ?int, completion_tokens: ?int} */
    private function extrairTextoECusto(string $saida): array
    {
        return match ($this->responseFormat) {
            'single_json' => $this->extrairDeSingleJson($saida),
            'json_stream' => $this->extrairDeJsonStream($saida),
            default       => ['texto' => trim($saida) === '' ? null : $saida, 'custo' => null, 'prompt_tokens' => null, 'completion_tokens' => null],
        };
    }

    /** @return array{texto: ?string, custo: ?float, prompt_tokens: ?int, completion_tokens: ?int} */
    private function extrairDeSingleJson(string $saida): array
    {
        $dados = json_decode($saida, true);
        if (!is_array($dados)) {
            return ['texto' => null, 'custo' => null, 'prompt_tokens' => null, 'completion_tokens' => null];
        }

        return [
            'texto'             => $this->valorEmCaminho($dados, $this->resultField),
            'custo'             => $this->somarCaminho($dados, $this->costField),
            'prompt_tokens'     => $this->somarCaminho($dados, $this->promptTokensField),
            'completion_tokens' => $this->somarCaminho($dados, $this->completionTokensField),
        ];
    }

    /**
     * Formato real do opencode (`run --format json`, confirmado em `StepFinishPart`/`emit()` no
     * código-fonte): o texto final vem de um evento tipo "text" (`part.text`), DIFERENTE do(s)
     * evento(s) tipo "step_finish"/"step-finish" que carregam custo/tokens (`part.cost`,
     * `part.tokens.{input,output}`) — podem existir VÁRIOS "step_finish" numa única resposta
     * (loop de tool-call interno do harness antes da resposta final), então custo/tokens são
     * somados por TODOS eles, não só o último.
     *
     * @return array{texto: ?string, custo: ?float, prompt_tokens: ?int, completion_tokens: ?int}
     */
    private function extrairDeJsonStream(string $saida): array
    {
        $linhas = array_filter(explode("\n", $saida), fn($l) => trim($l) !== '');

        $texto             = null;
        $custo             = null;
        $promptTokens      = null;
        $completionTokens  = null;

        foreach ($linhas as $linha) {
            $evento = json_decode($linha, true);
            if (!is_array($evento)) {
                continue;
            }
            $tipo = $evento['type'] ?? null;

            if ($tipo === 'text') {
                $texto = $this->valorEmCaminho($evento, $this->resultField) ?? $texto;
                continue;
            }

            if (in_array($tipo, ['step_finish', 'step-finish'], true)) {
                $custo            = $this->somarValores($custo, $this->somarCaminho($evento, $this->costField));
                $promptTokens     = $this->somarValores($promptTokens, $this->somarCaminho($evento, $this->promptTokensField));
                $completionTokens = $this->somarValores($completionTokens, $this->somarCaminho($evento, $this->completionTokensField));
            }
        }

        return ['texto' => $texto, 'custo' => $custo, 'prompt_tokens' => $promptTokens, 'completion_tokens' => $completionTokens];
    }

    /** Lookup simples por dot-path (ex: 'response', ou 'part.text') — sem suporte a `*`, pro texto (não-numérico). */
    private function valorEmCaminho(array $dados, ?string $caminho): mixed
    {
        if ($caminho === null) {
            return null;
        }

        $no = $dados;
        foreach (explode('.', $caminho) as $segmento) {
            if (!is_array($no) || !array_key_exists($segmento, $no)) {
                return null;
            }
            $no = $no[$segmento];
        }
        return $no;
    }

    /**
     * Dot-path com suporte a `*` como segmento curinga: soma o valor numérico encontrado em CADA
     * item do array naquele nível (ex: 'stats.models.*.tokens.prompt', quando a CLI reporta tokens
     * por model — chave dinâmica, não um total único já pronto). Sem `*`, é só um lookup normal.
     */
    private function somarCaminho(array $dados, ?string $caminho): int|float|null
    {
        if ($caminho === null) {
            return null;
        }
        return $this->somarCaminhoRecursivo($dados, explode('.', $caminho));
    }

    /** @param string[] $segmentos */
    private function somarCaminhoRecursivo(mixed $no, array $segmentos): int|float|null
    {
        if (empty($segmentos)) {
            return is_numeric($no) ? $no + 0 : null;
        }
        if (!is_array($no)) {
            return null;
        }

        $segmento = array_shift($segmentos);

        if ($segmento === '*') {
            $soma = null;
            foreach ($no as $valor) {
                $parcial = $this->somarCaminhoRecursivo($valor, $segmentos);
                if ($parcial !== null) {
                    $soma = ($soma ?? 0) + $parcial;
                }
            }
            return $soma;
        }

        return array_key_exists($segmento, $no) ? $this->somarCaminhoRecursivo($no[$segmento], $segmentos) : null;
    }

    /** Soma null-safe: parcela null não participa (não vira 0); 2 null's ficam null. */
    private function somarValores(int|float|null $a, int|float|null $b): int|float|null
    {
        if ($a === null && $b === null) {
            return null;
        }
        return ($a ?? 0) + ($b ?? 0);
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
