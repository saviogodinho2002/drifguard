<?php

namespace Saviogodinho2002\DriftGuard\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Saviogodinho2002\DriftGuard\Clients\OpenRouterAnalysisClient;
use Saviogodinho2002\DriftGuard\Tests\TestCase;

/**
 * Gap real achado em teste de usuário: `chat()` decodificava a resposta da OpenRouter e só lia
 * `choices[0].message`, descartando `usage` (custo/tokens) mesmo a API retornando esse dado.
 * Validado com chamada real à API antes de escrever este teste: `usage.cost` vem preenchido por
 * padrão (sem parâmetro extra), confirmado também na doc oficial (campo `cost` marcado opcional
 * dentro de `usage` — daqui o `?? null` defensivo, não um valor sempre garantido).
 */
class OpenRouterAnalysisClientTest extends TestCase
{
    private function clientComRespostaFake(array $corpoResposta): OpenRouterAnalysisClient
    {
        $mock = new MockHandler([new Response(200, [], json_encode($corpoResposta))]);
        $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

        return new OpenRouterAnalysisClient(apiKey: 'fake-key', httpClient: $httpClient);
    }

    public function test_usage_cost_and_tokens_are_extracted_when_present(): void
    {
        $client = $this->clientComRespostaFake([
            'choices' => [['message' => ['content' => null, 'tool_calls' => []]]],
            'usage'   => ['prompt_tokens' => 9, 'completion_tokens' => 4, 'total_tokens' => 13, 'cost' => 8.7e-05],
        ]);

        $resultado = $client->chat([['role' => 'user', 'content' => 'x']], []);

        $this->assertSame(8.7e-05, $resultado['usage']['cost_usd']);
        $this->assertSame(9, $resultado['usage']['prompt_tokens']);
        $this->assertSame(4, $resultado['usage']['completion_tokens']);
    }

    public function test_usage_fields_stay_null_when_provider_omits_them(): void
    {
        $client = $this->clientComRespostaFake([
            'choices' => [['message' => ['content' => null, 'tool_calls' => []]]],
            // sem chave 'usage' — cenário que a doc da OpenRouter permite (campos opcionais)
        ]);

        $resultado = $client->chat([['role' => 'user', 'content' => 'x']], []);

        $this->assertNull($resultado['usage']['cost_usd']);
        $this->assertNull($resultado['usage']['prompt_tokens']);
        $this->assertNull($resultado['usage']['completion_tokens']);
    }

    public function test_content_and_tool_calls_still_extracted_correctly(): void
    {
        $client = $this->clientComRespostaFake([
            'choices' => [['message' => [
                'content'    => null,
                'tool_calls' => [['id' => 'call_1', 'function' => ['name' => 'propose_update', 'arguments' => '{}']]],
            ]]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ]);

        $resultado = $client->chat([], []);

        $this->assertCount(1, $resultado['tool_calls']);
        $this->assertSame('propose_update', $resultado['tool_calls'][0]['function']['name']);
    }
}
