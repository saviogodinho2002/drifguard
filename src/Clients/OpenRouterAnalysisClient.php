<?php

namespace Saviogodinho2002\Drifguard\Clients;

use GuzzleHttp\Client;
use Saviogodinho2002\Drifguard\Contracts\AnalysisClient;

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
    ) {
    }

    public function chat(array $messages, array $tools): array
    {
        $client = new Client(['timeout' => $this->timeoutSeconds]);

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

        return [
            'content'   => $message['content'] ?? null,
            'tool_calls' => $message['tool_calls'] ?? [],
        ];
    }
}
