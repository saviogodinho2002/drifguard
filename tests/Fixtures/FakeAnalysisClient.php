<?php

namespace Saviogodinho2002\DriftGuard\Tests\Fixtures;

use Saviogodinho2002\DriftGuard\Contracts\AnalysisClient;

/**
 * Cliente de análise fake pra testes — devolve respostas pré-programadas em vez de chamar uma API
 * real. Fila de respostas consumida em ordem, uma por chamada de chat().
 */
class FakeAnalysisClient implements AnalysisClient
{
    /** @var array<int, array{content: ?string, tool_calls: array, usage: array}> */
    private array $fila = [];
    public array $mensagensRecebidas = [];

    /** @return array{cost_usd: ?float, prompt_tokens: ?int, completion_tokens: ?int} */
    private static function usageVazio(): array
    {
        return ['cost_usd' => null, 'prompt_tokens' => null, 'completion_tokens' => null];
    }

    public function enqueueProposeUpdate(array $args, ?array $usage = null): self
    {
        $this->fila[] = [
            'content'    => null,
            'tool_calls' => [[
                'id'       => 'call_1',
                'function' => ['name' => 'propose_update', 'arguments' => json_encode($args)],
            ]],
            'usage' => $usage ?? self::usageVazio(),
        ];
        return $this;
    }

    public function enqueueAskQuestion(string $question, ?array $usage = null): self
    {
        $this->fila[] = [
            'content'    => null,
            'tool_calls' => [[
                'id'       => 'call_1',
                'function' => ['name' => 'ask_question', 'arguments' => json_encode(['question' => $question])],
            ]],
            'usage' => $usage ?? self::usageVazio(),
        ];
        return $this;
    }

    public function enqueueRequestFile(string $path, ?array $usage = null): self
    {
        $this->fila[] = [
            'content'    => null,
            'tool_calls' => [[
                'id'       => 'call_1',
                'function' => ['name' => 'request_file', 'arguments' => json_encode(['path' => $path])],
            ]],
            'usage' => $usage ?? self::usageVazio(),
        ];
        return $this;
    }

    /** @param array<int, array{path: string, method: string}> $requests */
    public function enqueueRequestMethod(array $requests, ?array $usage = null): self
    {
        $this->fila[] = [
            'content'    => null,
            'tool_calls' => [[
                'id'       => 'call_1',
                'function' => ['name' => 'request_method', 'arguments' => json_encode(['requests' => $requests])],
            ]],
            'usage' => $usage ?? self::usageVazio(),
        ];
        return $this;
    }

    public function chat(array $messages, array $tools): array
    {
        $this->mensagensRecebidas[] = $messages;
        return array_shift($this->fila) ?? ['content' => null, 'tool_calls' => [], 'usage' => self::usageVazio()];
    }
}
