<?php

namespace Saviogodinho2002\DriftGuard\Tests\Fixtures;

use Saviogodinho2002\DriftGuard\Contracts\AnalysisClient;

/**
 * Cliente de análise fake pra testes — devolve respostas pré-programadas em vez de chamar uma API
 * real. Fila de respostas consumida em ordem, uma por chamada de chat().
 */
class FakeAnalysisClient implements AnalysisClient
{
    /** @var array<int, array{content: ?string, tool_calls: array}> */
    private array $fila = [];
    public array $mensagensRecebidas = [];

    public function enqueueProposeUpdate(array $args): self
    {
        $this->fila[] = [
            'content'    => null,
            'tool_calls' => [[
                'id'       => 'call_1',
                'function' => ['name' => 'propose_update', 'arguments' => json_encode($args)],
            ]],
        ];
        return $this;
    }

    public function enqueueAskQuestion(string $question): self
    {
        $this->fila[] = [
            'content'    => null,
            'tool_calls' => [[
                'id'       => 'call_1',
                'function' => ['name' => 'ask_question', 'arguments' => json_encode(['question' => $question])],
            ]],
        ];
        return $this;
    }

    public function enqueueRequestFile(string $path): self
    {
        $this->fila[] = [
            'content'    => null,
            'tool_calls' => [[
                'id'       => 'call_1',
                'function' => ['name' => 'request_file', 'arguments' => json_encode(['path' => $path])],
            ]],
        ];
        return $this;
    }

    /** @param array<int, array{path: string, method: string}> $requests */
    public function enqueueRequestMethod(array $requests): self
    {
        $this->fila[] = [
            'content'    => null,
            'tool_calls' => [[
                'id'       => 'call_1',
                'function' => ['name' => 'request_method', 'arguments' => json_encode(['requests' => $requests])],
            ]],
        ];
        return $this;
    }

    public function chat(array $messages, array $tools): array
    {
        $this->mensagensRecebidas[] = $messages;
        return array_shift($this->fila) ?? ['content' => null, 'tool_calls' => []];
    }
}
