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
    | Default é base_path('driftguard') — raiz do projeto, DE PROPÓSITO fora de storage/ (que o
    | .gitignore padrão do Laravel exclui). context.json guarda last_commit_hash e perguntas
    | pendentes/respondidas — se ficar de fora do git, cada dev/agente tem seu próprio estado local
    | e nunca vê o que outro já analisou ou respondeu. Se quiser mesmo assim NÃO commitar isso,
    | aponte pra storage_path('app/driftguard') e adicione ao seu .gitignore.
    */
    'storage_path' => base_path('driftguard'),

    /*
    |--------------------------------------------------------------------------
    | Cliente de análise (LLM)
    |--------------------------------------------------------------------------
    | 3 formas de escolher o provedor, em ordem de esforço:
    | 1. 'driver' => 'openrouter' (default) — API stateless, pré-configurada com um model Claude.
    |    Troque 'model' por qualquer slug que a OpenRouter aceite.
    | 2. 'driver' => 'cli_harness' — invoca um agente-CLI (Claude Code, Gemini CLI, opencode) como
    |    subprocesso; o harness explora o código sozinho em vez de receber snippet pré-empacotado.
    |    Escolha um preset em 'cli_harness_preset' (default 'claude'; ajuste 'cli_harness' se a sua
    |    versão de qualquer uma das CLIs usar uma flag diferente do preset). Override com null
    |    explícito é sempre respeitado (nunca vira o default do preset 'claude' por engano) — é
    |    assim que 'gemini'/'opencode' conseguem deixar dir_flag/tools_flag sem equivalente
    |    conhecido. Trade-offs: overhead de processo por chamada pode ser maior que 1 POST HTTP, já
    |    que o harness gasta turnos explorando o código sozinho — mas a saída tende a ser mais rica
    |    (cross-referências que o harness descobre sozinho via Grep, não limitadas ao orçamento de
    |    max_snippet_chars). O CLI precisa estar AUTENTICADO no ambiente que roda driftguard:analyze
    |    (login prévio ou API key do provedor da CLI — pode não servir bem pra CI/headless sem
    |    sessão interativa). Custo/tokens vêm do relatório da própria CLI (usage['cost_usd'/
    |    'prompt_tokens'/'completion_tokens'], best-effort — null quando a CLI não expõe aquele
    |    campo especificamente; Gemini CLI expõe tokens mas não custo em dólar, confirmado lendo o
    |    schema de tipos do próprio código-fonte da CLI, não só a documentação em prosa).
    | 3. Bind da sua própria implementação de Contracts\AnalysisClient no seu ServiceProvider —
    |    qualquer outro provedor (Anthropic direto, OpenAI, outro CLI com saída totalmente diferente).
    */
    'llm' => [
        'driver'       => 'openrouter', // 'openrouter' | 'cli_harness'
        'api_key_env'  => 'OPENROUTER_API_KEY',
        'model'        => 'anthropic/claude-sonnet-4-6',
        'max_tokens'   => 8000,
        'timeout'      => 300,

        // Só usado quando 'driver' => 'cli_harness'. Escolha um preset e opcionalmente
        // sobrescreva chaves específicas em 'cli_harness' (mesmo padrão de override do resto do pacote).
        'cli_harness_preset' => 'claude', // 'claude' | 'gemini' | 'opencode'

        // Trava allowed_base_path contra escrita no sistema de arquivos durante a exploração do
        // harness (ver Support\ReadOnlyLock) — funciona igual pros 3 presets, independente de a CLI
        // alvo ter allowlist de tool própria. Não funciona em Windows (chmod não impõe restrição real
        // de diretório lá); nesse caso é automaticamente ignorado, sem precisar desligar aqui.
        'readonly_lock' => true,

        'cli_harness'        => [
            // qualquer chave aqui sobrescreve o preset escolhido, ex: 'timeout' => 600
        ],
        'cli_harness_presets' => [
            'claude' => [ // validado ao vivo (chamada real contra um model de produção)
                'command'                 => 'claude',
                'extra_args'              => ['--output-format', 'json'],
                'response_format'         => 'single_json',
                'result_field'            => 'result',
                'cost_field'              => 'total_cost_usd',
                'prompt_tokens_field'     => 'usage.input_tokens',
                'completion_tokens_field' => 'usage.output_tokens',
                'dir_flag'                => '--add-dir',
                'tools_flag'              => '--allowedTools',
                'harness_tools'           => ['Read', 'Grep', 'Glob'],
            ],
            // Schema confirmado lendo o código-fonte da Gemini CLI (packages/core/src/output/types.ts
            // e telemetry/uiTelemetry.ts) + 1 chamada real autenticada batendo com o schema — mas não
            // reproduzível de forma confiável neste ambiente (credencial não persiste entre chamadas).
            // `-o json` devolve {session_id, response, stats, error, warnings}; tokens vêm por MODEL
            // (stats.models.<nome>.tokens.{prompt,candidates}, chave dinâmica — por isso o `*`
            // curinga, somado através de todos os models envolvidos na resposta). Sem campo de custo
            // em dólar em lugar nenhum do schema — não existe pra inventar.
            'gemini' => [
                'command'                 => 'gemini',
                'extra_args'              => ['--output-format', 'json'],
                'response_format'         => 'single_json',
                'result_field'            => 'response',
                'cost_field'              => null, // confirmado: não existe no schema da CLI
                'prompt_tokens_field'     => 'stats.models.*.tokens.prompt',
                'completion_tokens_field' => 'stats.models.*.tokens.candidates',
                'dir_flag'                => null, // sem flag de restrição de diretório equivalente ao --add-dir
                'tools_flag'              => null,
                'harness_tools'           => [],
            ],
            // Schema confirmado lendo o código-fonte do opencode (packages/opencode/src/cli/cmd/run.ts
            // e packages/schema/src/v1/session.ts, StepFinishPart) — não instalado/rodado ao vivo. O
            // texto final vem de um evento tipo "text" (part.text), DIFERENTE do(s) evento(s) tipo
            // "step_finish" que carregam custo/tokens (part.cost, part.tokens.{input,output}) — pode
            // haver mais de 1 "step_finish" numa resposta (tool-call loop interno), somados entre si.
            'opencode' => [
                'command'                 => 'opencode',
                'extra_args'              => ['run', '--format', 'json'],
                'response_format'         => 'json_stream',
                'result_field'            => 'part.text',
                'cost_field'              => 'part.cost',
                'prompt_tokens_field'     => 'part.tokens.input',
                'completion_tokens_field' => 'part.tokens.output',
                'dir_flag'                => null, // controle de diretório é via config do agente, não flag de runtime
                'tools_flag'              => null, // controle de permissão é via config do agente, não flag de runtime
                'harness_tools'           => [],
            ],
        ],
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
    | 'scope_class' (em vez de string, o driftguard GERA um arquivo .php de classe
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
    |         'llm_instructions' => 'Regra de acesso multi-tenant deste model. Primeiro confira o próprio ' .
    |             'model: global scopes (booted()/addGlobalScope), scopes locais, e relações que já ' .
    |             'implicam limite de tenant (ex: belongsTo(Empresa::class)). Se a regra não estiver ' .
    |             'visível aí, NÃO infira a partir de como um controller filtra manualmente — pode ser ' .
    |             'inconsistente entre pontos de entrada diferentes. Use request_file só se souber o ' .
    |             'path exato de um middleware/trait específico; senão, use ask_question.',
    |     ],
    | ],
    |
    | Nota sobre a instrução acima: o arquivo do PRÓPRIO model sempre entra inteiro no contexto (é a
    | fonte primária, nunca precisa pedir isso na instrução). O que NÃO entra automaticamente é
    | lógica de tenant que mora em middleware — se for o seu caso, adicione o diretório de
    | middlewares em 'supporting_paths' abaixo (dado estrutural: onde procurar), em vez de tentar
    | resolver isso só via instrução (que é sobre COMO julgar o que já está no contexto).
    */
    'fields' => [],

    /*
    |--------------------------------------------------------------------------
    | Path/namespace das classes de escopo geradas (type: scope_class)
    |--------------------------------------------------------------------------
    */
    'scope_class_path'      => app_path('DriftGuard/Scopes'),
    'scope_class_namespace' => 'App\\DriftGuard\\Scopes',

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

    /*
    |--------------------------------------------------------------------------
    | Orçamento de contexto por arquivo
    |--------------------------------------------------------------------------
    | Um controller/service grande pode estourar contexto/custo rápido. Pro arquivo de apoio, o
    | pacote tenta primeiro extrair só os métodos que mencionam o model. Pro arquivo do PRÓPRIO
    | model, abaixo deste teto vai sempre inteiro (é a fonte primária); acima dele, tenta extração
    | SEGURA primeiro (mantém todo método público, só remove overrides do Eloquent — nunca corta
    | regra de negócio real). Em qualquer um dos dois casos, se ainda estourar depois de tentar
    | extrair, cai pro conteúdo truncado neste teto (chars) com aviso — nunca estoura sem avisar.
    */
    'max_snippet_chars' => 6000,

    /*
    |--------------------------------------------------------------------------
    | Orçamento total combinado por model
    |--------------------------------------------------------------------------
    | Soma de TODOS os snippets de um model (arquivo do model + todo arquivo de apoio) — se
    | estourar, descarta arquivo de apoio (nunca o do próprio model) começando pelo de menor
    | conteúdo extraído. Espelha o MAX_TOTAL_CHARS do sistema original.
    */
    'max_total_snippet_chars' => 60000,

    /*
    |--------------------------------------------------------------------------
    | Nº máximo de arquivos de apoio por model
    |--------------------------------------------------------------------------
    | Um model referenciado em muitos controllers/services pode estourar contexto rápido. Espelha
    | o MAX_RELATED_FILES do sistema original.
    */
    'max_supporting_files' => 5,

    /*
    |--------------------------------------------------------------------------
    | Diretório permitido pra `request_file`
    |--------------------------------------------------------------------------
    | A IA pode pedir outro arquivo via tool-calling durante a análise — este é o
    | diretório-raiz fora do qual a leitura é recusada (ex: impede pedir um path
    | tipo '../../.env'). Default: raiz do projeto.
    */
    'allowed_base_path' => base_path(),

];
