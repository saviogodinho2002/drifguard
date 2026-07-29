<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Descoberta de models
    |--------------------------------------------------------------------------
    | Onde procurar seus models Eloquent, e em quais diretórios procurar arquivos
    | de apoio (controllers/requests/services) que os referenciam — usados como
    | contexto de código na hora de montar a análise.
    */
    'model_namespace' => 'App\\Models',
    'models_path'     => app_path('Models'),
    'supporting_paths' => [
        app_path('Http/Controllers'),
        app_path('Http/Requests'),
        app_path('Services'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Catálogo de saída
    |--------------------------------------------------------------------------
    | Onde fica o arquivo PHP que este pacote mantém sincronizado.
    */
    'output_path' => config_path('models.php'),

    /*
    |--------------------------------------------------------------------------
    | Storage interno (context.json / proposal.php / questions.md)
    |--------------------------------------------------------------------------
    */
    'storage_path' => storage_path('app/drifguard'),

    /*
    |--------------------------------------------------------------------------
    | Cliente de análise (LLM)
    |--------------------------------------------------------------------------
    | Implementação padrão: OpenRouter, pré-configurada com um model Claude. Troque
    | 'model' por qualquer slug que a OpenRouter aceite, ou faça bind de outra
    | implementação de Contracts\AnalysisClient no seu próprio ServiceProvider se
    | quiser usar outro provedor (Anthropic direto, OpenAI, etc.).
    */
    'llm' => [
        'api_key_env' => 'OPENROUTER_API_KEY',
        'model'       => 'anthropic/claude-sonnet-4-6',
        'max_tokens'  => 8000,
        'timeout'     => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Campos extras do catálogo
    |--------------------------------------------------------------------------
    | Além da base fixa (descricao, notas — propostos pela IA; tabela/campos/relacoes
    | — sempre por reflection, nunca propostos), você pode definir campos extras
    | específicos do SEU domínio aqui. Cada um vira um parâmetro real na chamada de
    | tool-calling, com a instrução que você escrever guiando a IA.
    |
    | Tipos suportados: 'string', 'enum' (exige 'enum_values'), 'array',
    | 'scope_class' (em vez de string, o drifguard GERA um arquivo .php de classe
    | de escopo de tenant, implementando Contracts\TenantScope — nunca guarda
    | código como string pra eval() depois).
    |
    | Exemplo (comentado — descomente e adapte pro seu domínio):
    |
    | 'fields' => [
    |     [
    |         'name'             => 'gatilhos',
    |         'type'             => 'string',
    |         'llm_instructions' => 'Frases/palavras que um usuário digitaria pra perguntar sobre este model, separadas por vírgula — usado por um roteador de chat.',
    |     ],
    |     [
    |         'name'             => 'classe_acesso',
    |         'type'             => 'enum',
    |         'enum_values'      => ['publico', 'restrito'],
    |         'llm_instructions' => 'publico = dado de referência sem restrição de tenant; restrito = precisa de escopo_tenant.',
    |     ],
    |     [
    |         'name'             => 'escopo_tenant',
    |         'type'             => 'scope_class',
    |         'llm_instructions' => 'Corpo do método apply(): restrinja $query ao tenant do usuário em $context. Retorne $query.',
    |     ],
    | ],
    */
    'fields' => [],

    /*
    |--------------------------------------------------------------------------
    | Path/namespace das classes de escopo geradas (type: scope_class)
    |--------------------------------------------------------------------------
    */
    'scope_class_path'      => app_path('Drifguard/Scopes'),
    'scope_class_namespace' => 'App\\Drifguard\\Scopes',

    /*
    |--------------------------------------------------------------------------
    | Regras extras de prompt (meta-instrução de formato/schema)
    |--------------------------------------------------------------------------
    | String literal OU caminho pra um arquivo .md/.txt que você mantém no seu
    | próprio projeto, concatenado ao prompt-base genérico do pacote. Use isso pra
    | convenções específicas do seu app (ex: "nunca use tal método aqui, use tal
    | outro por causa de tal bug conhecido") — não pra conhecimento de negócio por
    | model, isso é 'context_docs' abaixo.
    */
    'extra_prompt_rules' => null,

    /*
    |--------------------------------------------------------------------------
    | Documentos de contexto de negócio, por model
    |--------------------------------------------------------------------------
    | Regra de negócio que NÃO é inferível só lendo o código (motivo histórico,
    | decisão de produto, exceção). Path base relativo à raiz do projeto.
    */
    'context_docs' => [
        'base_path'       => base_path(),
        // Mapeamento explícito: 'map' => ['Despesa' => 'docs/regras/despesa.md'],
        'map'             => [],
        // Convenção automática por nome — se o arquivo existir, é incluído sem precisar listar.
        'convention_path' => null, // ex: 'docs/regras/{model}.md'
    ],

];
