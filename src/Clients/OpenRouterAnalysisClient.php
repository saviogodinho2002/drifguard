<?php

namespace Saviogodinho2002\DriftGuard\Clients;

use GuzzleHttp\Client;
use Saviogodinho2002\DriftGuard\Contracts\AnalysisClient;

/**
 * Implementação padrão de AnalysisClient — OpenRouter, pré-configurada com um slug Claude, mas
 * tudo ajustável via config (model/key/timeout/headers). Um app-host que queira outro provedor
 * (Anthropic direto, OpenAI, etc.) faz bind de outra implementação de AnalysisClient no próprio
 * ServiceProvider — não precisa mexer aqui.
 */
class OpenRouterAnalysisClient implements AnalysisClient
{
    private const URL = 'https://openrouter.ai/api/v1/chat/completions';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'anthropic/claude-sonnet-4-6',
        private readonly int $maxTokens = 8000,
        private readonly int $timeoutSeconds = 300,
        private readonly array $extraHeaders = [],
        /** Seam de teste — injete um Client com MockHandler pra não bater na API real. Null usa um Client de verdade. */
        private readonly ?Client $httpClient = null,
    ) {
    }

    public function chat(array $messages, array $tools): array
    {
        $client = $this->httpClient ?? new Client(['timeout' => $this->timeoutSeconds]);

        $response = $client->post(self::URL, [
            'headers' => array_merge([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type'  => 'application/json',
            ], $this->extraHeaders),
            'json' => [
                'model'       => $this->model,
                'messages'    => $messages,
                'tools'       => $tools,
                'tool_choice' => 'required',
                'max_tokens'  => $this->maxTokens,
            ],
        ]);

        $dados   = json_decode((string) $response->getBody(), true);
        $message = $dados['choices'][0]['message'] ?? [];
        $usage   = $dados['usage'] ?? [];

        return [
            'content'    => $message['content'] ?? null,
            'tool_calls' => $message['tool_calls'] ?? [],
            'usage'      => [
                'cost_usd'          => $usage['cost'] ?? null,
                'prompt_tokens'     => $usage['prompt_tokens'] ?? null,
                'completion_tokens' => $usage['completion_tokens'] ?? null,
            ],
        ];
    }
}
