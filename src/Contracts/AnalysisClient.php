<?php

namespace Saviogodinho2002\Drifguard\Contracts;

/**
 * Abstração sobre o provedor de LLM usado pra analisar um model e propor atualização de catálogo.
 * O pacote entrega uma implementação padrão (OpenRouter + Claude), mas o app-host pode fazer bind
 * de outra implementação (Anthropic direto, OpenAI, etc.) no service container, desde que fale o
 * mesmo protocolo de tool-calling (mensagens + definição de tools, resposta com tool_calls).
 */
interface AnalysisClient
{
    /**
     * Envia uma conversa (histórico de mensagens, formato estilo OpenAI chat) com um conjunto de
     * tools disponíveis (formato estilo OpenAI function-calling) e devolve a resposta crua do
     * assistente — o chamador decide o que fazer com content/tool_calls (propose_update,
     * ask_question, request_file são convenções do ModelSyncService, não deste contrato).
     *
     * @param array<int, array{role: string, content: mixed}> $messages
     * @param array<int, array{type: string, function: array}> $tools
     * @return array{content: ?string, tool_calls: array<int, array{id: string, function: array{name: string, arguments: string}}>}
     */
    public function chat(array $messages, array $tools): array;
}
