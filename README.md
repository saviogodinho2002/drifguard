# DriftGuard

[![Latest Version](https://img.shields.io/packagist/v/saviogodinho2002/driftguard.svg)](https://packagist.org/packages/saviogodinho2002/driftguard)
[![License](https://img.shields.io/packagist/l/saviogodinho2002/driftguard.svg)](LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/saviogodinho2002/driftguard.svg)](composer.json)

Mantém um catálogo PHP curado dos seus models Eloquent sincronizado com o código real, usando um
LLM (configurável) num fluxo analyze → revisar → apply. Detecção de mudança via `git diff`,
extração estrutural sempre via **reflection real** (nunca por prosa da IA), e um passo de revisão
humana antes de qualquer escrita.

## Por quê

Documentar model manualmente desatualiza. Deixar uma IA reescrever tudo sem revisão arrisca perder
curadoria manual. DriftGuard concilia os dois: fatos estruturais (tabela, campos, relações) sempre
vêm de reflection — nunca da IA; conhecimento de negócio (descrição, notas, e qualquer campo extra
que você definir) é proposto pela IA mas **nunca aplicado sem você revisar o diff antes**.

## Instalação

```bash
composer require saviogodinho2002/driftguard
php artisan vendor:publish --tag=driftguard-config
```

Configure `OPENROUTER_API_KEY` no seu `.env` (o cliente padrão usa OpenRouter + um model Claude —
troque em `config/driftguard.php` ou faça bind de outra implementação de `Contracts\AnalysisClient`
no seu próprio `ServiceProvider` se quiser outro provedor).

## Uso

```bash
php artisan driftguard:doctor           # valida a config inteira (sem chamar o LLM) — rode primeiro
php artisan driftguard:analyze --dry-run  # mostra modo/models/prévia, sem chamar o LLM
php artisan driftguard:analyze          # analisa o que mudou desde a última rodada (git diff)
php artisan driftguard:analyze --force  # reanalisa tudo
php artisan driftguard:analyze --full   # ignora os orçamentos de contexto (manda tudo inteiro, sem corte)
php artisan driftguard:apply --dry-run  # mostra o diff sem aplicar
php artisan driftguard:apply            # aplica (pede confirmação)
```

`driftguard:doctor` também avisa (nível `WARN`, nunca falha o exit code) quando uma entrada de
`config/models.php` não corresponde a nenhum model encontrado em `models_path` — sinal de que o
model foi renomeado, movido ou apagado desde a última análise. O pacote **nunca remove essa entrada
sozinho** (o catálogo é curado à mão, igual qualquer outro campo — ver "Segurança" abaixo); reveja
manualmente e decida: se foi rename/move, ajuste `models_path`/o nome da classe e rode `analyze`
de novo; se o model saiu de vez, apague a entrada à mão em `config/models.php`.

Se você já analisou tudo por fora (ou só quer marcar "a partir de agora, reanalise o que mudar")
sem pagar o custo de rodar `--force` contra todos os models de novo:

```bash
php artisan driftguard:init             # grava context.json com o HEAD atual, sem chamar o LLM
php artisan driftguard:init --force     # sobrescreve um context.json existente (cuidado: perde
                                        # pending_questions/scope_hashes já acumulados nele)
```

Perguntas pendentes (`ask_question`) — responda sem editar `questions.md` à mão:

```bash
php artisan driftguard:answer Post Sim, published_at nulo sempre significa rascunho.
php artisan driftguard:analyze          # próxima rodada já inclui Post com a resposta no contexto
```

Introspecção (sem chamar o LLM):

```bash
php artisan driftguard:fields                 # lista os campos extras (FieldSpec) configurados
php artisan driftguard:context:list           # mostra qual doc de contexto seria usado por model
php artisan driftguard:context:list --model=Post
```

### Uso por um agente de IA (CLI headless)

Todo command aceita `--json` (saída estruturada em vez de tabela/cores):

```bash
php artisan driftguard:doctor --json                # {ok: bool, checks: [{status, item, detalhe}, ...]}
php artisan driftguard:analyze --dry-run --json    # prévia sem custo, parseável
php artisan driftguard:analyze --json              # roda e devolve {mode, models, proposals, questions}
php artisan driftguard:apply --json --dry-run       # {status: "dry_run", diff: [...]}
php artisan driftguard:apply --json --force         # aplica sem prompt interativo, {status: "applied", ...}
php artisan driftguard:answer Post Sim --json       # {status: "answered"|"not_found", model, question}
```

`driftguard:apply --json` **sem** `--force` nunca aplica — devolve `{status: "confirmation_required", diff}`
e sai com código de erro, pra uma execução headless nunca gravar mudança por engano só por ter
esquecido `--dry-run`.

## Customizando pro seu domínio

Tudo em `config/driftguard.php`, publicado no seu próprio projeto — edite livremente:

- **`fields`** — campos extras do seu domínio (além da base `descricao`/`notas`/`tabela`/`campos`/`relacoes`
  que o pacote já cobre). Tipos: `string`, `enum`, `array`, ou `scope_class` (gera um arquivo `.php`
  de classe de escopo de tenant de verdade, implementando `Contracts\TenantScope` — nunca guarda
  código como string pra `eval()` depois). Duas formas de autoria, misturáveis na mesma lista:

  ```php
  // array cru
  'fields' => [
      ['name' => 'gatilhos', 'type' => 'string', 'llm_instructions' => 'termos de busca'],
  ],

  // ou fluente (com autocomplete, valida no momento da construção)
  use Saviogodinho2002\DriftGuard\Support\FieldSpec;

  'fields' => [
      FieldSpec::string('gatilhos')->instructions('termos de busca'),
      FieldSpec::enum('classe_acesso', ['publico', 'restrito'])->instructions('...')->required(),
      FieldSpec::scopeClass('escopo_tenant')->instructions('...'),
  ],
  ```

  Rode `php artisan driftguard:doctor` depois de editar — valida cada `FieldSpec` (e os demais paths
  da config) sem chamar o LLM, apontando exatamente qual entrada está malformada.

- **`context_docs`** — aponte arquivos `.md` com regra de negócio que não dá pra inferir só lendo o
  código (motivo histórico, decisão de produto). Entra como contexto extra pra IA, nunca substitui
  o que reflection já determina. Veja o que está resolvido com `driftguard:context:list`.
- **`extra_prompt_rules`** — meta-instrução de formato/convenção específica do seu app (não é
  conhecimento de negócio por model — isso é `context_docs`).
- **`max_snippet_chars`** — teto de tamanho pra um arquivo (apoio OU o próprio model) acima do qual
  o pacote tenta extrair só a parte relevante antes de mandar tudo. Pro arquivo de apoio, extrai só
  os métodos que mencionam o model. Pro arquivo do PRÓPRIO model, tenta extração SEGURA primeiro
  (mantém todo método público — nunca corta regra de negócio real, só remove overrides do Eloquent
  puramente boilerplate). Em qualquer um dos dois, se ainda estourar depois de tentar extrair, cai
  pro conteúdo truncado com aviso — nunca estoura sem avisar.
- **`max_total_snippet_chars`** (default 60000) — orçamento combinado somando TODOS os snippets de
  1 model (arquivo do model + apoio); se estourar, descarta arquivo de apoio (nunca o do model)
  começando pelos de menor conteúdo extraído.
- **`max_supporting_files`** (default 5) — teto de quantos arquivos de apoio são coletados por
  model, antes mesmo de montar o contexto.
- **`--full`** (flag do `driftguard:analyze`, não é config) — ignora os 3 limites acima só na rodada
  atual, mandando tudo inteiro. Não muda quais models entram (isso é `--force`/`--model`/diff
  normal), só quanto conteúdo de cada um. Útil pra uma auditoria pontual ou a 1ª análise cuidadosa
  de um model crítico, sem precisar mexer na config publicada só por causa dessa rodada.
- **`request_method`** — quando um snippet do próprio model (ou de um arquivo de apoio) vem com
  aviso de "método(s) descartado(s) por orçamento", a IA pode pedir de volta só os métodos
  específicos que faltaram (os nomes exatos aparecem no aviso) em vez do arquivo inteiro — inclusive
  vários de uma vez, numa única chamada. Complementa `request_file` (que pede o arquivo inteiro):
  use `request_method` quando só falta método específico, `request_file` quando falta o arquivo
  todo (ex: um arquivo que nem chegou a entrar no contexto).
- **`allowed_base_path`** — diretório fora do qual a IA não pode ler arquivo via `request_file`
  durante a análise (default: raiz do projeto).
- **`storage_path`** — default `base_path('driftguard')` (raiz do projeto), **de propósito fora de
  `storage/`** — `context.json` guarda `last_commit_hash` e perguntas pendentes/respondidas; se
  ficar de fora do git (`storage/` é ignorado por padrão no Laravel), cada dev/agente vê um estado
  local diferente, sem saber o que outro já analisou ou respondeu. Se realmente quiser esse arquivo
  fora do git, aponte pra `storage_path('app/driftguard')` e adicione ao seu `.gitignore` você mesmo.
- **`models_path`/`supporting_paths`/`model_namespace`/`output_path`** — todos os caminhos usados
  são configuráveis, nada fixo em `app/Models`.

## Provedor alternativo: harness de CLI

Além de OpenRouter (padrão) e bind da sua própria `Contracts\AnalysisClient` (sempre suportado, veja
o comentário em `DriftGuardServiceProvider`), o pacote traz um 3º caminho: invocar um agente-CLI
(Claude Code, Gemini CLI, opencode) como subprocesso, em vez de uma API stateless. A diferença real
é que o harness explora o código **sozinho** (Read/Grep próprios, restritos ao seu projeto) em vez
de receber um snippet pré-empacotado pelo orçamento de contexto do driftguard.

```php
// config/driftguard.php
'llm' => [
    'driver'             => 'cli_harness',
    'cli_harness_preset' => 'claude', // 'claude' | 'gemini' | 'opencode'
],
```

O preset `claude` usa o Claude Code CLI: a saída é um JSON limpo, exatamente no formato pedido, com
regras de negócio numeradas e cross-referências que o harness descobre sozinho via Grep (sem
precisar que você cite o arquivo relacionado no prompt). `gemini`/`opencode` seguem a documentação
oficial de cada CLI; se a sua versão usar uma flag diferente, sobrescreva qualquer chave em
`llm.cli_harness` (mesmo padrão de override do resto do pacote):

```php
'cli_harness' => [
    'timeout' => 600, // por exemplo, sem precisar redefinir o preset inteiro
],
```

**Override com `null` explícito é respeitado** — se você precisar zerar uma flag que um preset já
define (ex: forçar `'dir_flag' => null` no preset `claude` porque sua versão da CLI mudou o nome da
flag), `null` de propósito NUNCA é silenciosamente trocado pelo default do preset. Isso vale tanto
pra chave que você sobrescreve quanto pras que os próprios presets `gemini`/`opencode` já deixam
`null` (sem flag de restrição de diretório equivalente nessas CLIs) — o driftguard nunca injeta uma
flag específica de outra CLI (como `--add-dir`/`--allowedTools`, do Claude Code CLI) só porque o
preset não definiu uma equivalente.

**Custo por chamada só aparece no log** — o contrato `AnalysisClient` não tem campo de custo (só
`content`/`tool_calls`), então `CliHarnessAnalysisClient` registra o custo de cada chamada via
`Log::info('[driftguard] CliHarnessAnalysisClient: custo da chamada', [...])` sempre que o formato
escolhido expõe um `cost_field` — confira seu log de aplicação (`storage/logs/laravel.log` por
padrão) pra acompanhar gasto real.

**Trade-offs a considerar**:
- **Custo/tempo por chamada pode ser maior que 1 POST HTTP no OpenRouter.** O harness gasta turnos
  explorando o código (Read/Grep) antes de responder, em vez de receber tudo já pronto — isso soma
  tempo e, se o provedor cobrar por uso, custo por cima do que uma chamada HTTP simples custaria. Em
  troca, a saída tende a ser mais rica que o que `extractSafeParts()`/`packWithinBudget()` conseguem
  com um orçamento fixo de contexto, já que o harness explora o que precisar, sem teto pré-definido.
- **O custo "equivalente de API" só é custo real se o provedor cobrar por uso.** Numa assinatura de
  valor fixo com folga de uso, o custo marginal de rodar isso pode ser ~US$0 — a resposta de "vale a
  pena" depende do seu plano, não de um número fixo.
- **O CLI precisa estar AUTENTICADO no ambiente que roda `driftguard:analyze`** (login prévio ou API
  key do provedor da CLI) — diferente de só ter uma env var configurada; pode não servir bem pra
  CI/headless sem uma sessão interativa já estabelecida.
- **Rastreio de custo depende do CLI.** Claude Code CLI expõe `total_cost_usd` direto no
  `--output-format json`. Gemini CLI ainda não expõe custo em modo headless na versão atual (preset
  `gemini` já reflete isso, `cost_field => null`). opencode expõe custo num evento `step_finish`
  dentro de um stream de JSON, não num objeto único.

## Multi-tenancy: escrevendo uma boa instrução pra `scope_class`

`scope_class` é o field mais delicado de instruir bem, porque a resposta certa depende de *onde* a
regra de tenant realmente mora no seu app — e isso varia. Dois princípios ajudam a escrever a
instrução:

**1. O que já está garantido no contexto, sem precisar pedir.**
O arquivo do próprio model entra inteiro sempre que cabe no orçamento (`max_snippet_chars`); acima
disso, a extração segura **sempre preserva** `booted()`/`addGlobalScope`, scopes locais
(`scopeXxx()`) e métodos de relação (são públicos — a extração segura nunca corta método público)
— então "olhe global scopes, scopes locais, e relações que já implicam tenant
(`belongsTo(Empresa::class)`)" a IA já tem material pra fazer sozinha de qualquer forma. Não
precisa instruir isso, é estrutural.

**2. O que não está garantido — e onde configurar, não instruir.**
Arquivos de apoio só entram se estiverem em `supporting_paths` **e** referenciarem o model
(`ModelDiscovery::supportingFilesForModel()`). Se a lógica de tenant mora num **middleware** (comum
em apps multi-tenant — resolve o tenant antes de qualquer controller/model ser tocado), esse arquivo
só entra no contexto se você adicionar o diretório de middlewares em `supporting_paths`. Isso é
"onde procurar" — resolve-se na config, não pedindo pra IA adivinhar ou usar `request_file` às
cegas.

**Cuidado com "olhe o controller e replique".** Controller filtrando manualmente
(`->where('empresa_id', $user->empresa_id)` espalhado em várias actions) é exatamente o anti-padrão
que `scope_class` existe pra resolver — centralizar a regra numa classe só. Se a instrução mandar
"replique o que o controller faz" e existirem 2-3 pontos de entrada (ex: API + painel admin +
import em lote) filtrando de formas ligeiramente diferentes — cenário comum em apps multi-tenant
que cresceram organicamente, não hipotético —, a IA vai escolher uma arbitrariamente. Instrua-a a preferir `ask_question` nesse caso, nunca inferir.

Instrução recomendada como ponto de partida (adapte `Empresa`/`empresa_id` pro seu domínio):

```php
FieldSpec::scopeClass('escopo_tenant')->instructions(
    'Regra de acesso multi-tenant deste model. Primeiro confira o próprio model: global scopes ' .
    '(booted()/addGlobalScope), scopes locais, e relações que já implicam limite de tenant ' .
    '(ex: belongsTo(Empresa::class)). Se a regra não estiver visível aí, NÃO infira a partir de ' .
    'como um controller filtra manualmente — pode ser inconsistente entre pontos de entrada ' .
    'diferentes. Use request_file pra pedir um middleware/trait específico só se souber o path ' .
    'exato; senão, use ask_question.'
),
```

## Perguntas pendentes e o modo `rerun`

Quando a IA usa `ask_question` em vez de propor uma atualização, o model fica pendente até alguém
responder — `php artisan driftguard:answer {model} {resposta}` (sem precisar editar `questions.md`).
A próxima `driftguard:analyze` (sem `--force`/`--model`) detecta automaticamente qualquer model com
pergunta respondida e o inclui na rodada — a resposta anterior entra no prompt como contexto humano
autoritativo, ao lado de `context_docs`.

## Segurança

- Nada é escrito sem um passo de `--dry-run`/confirmação (`driftguard:apply`).
- Campo do tipo `scope_class` roda checagem sintática (`php -l`) antes de gravar qualquer classe —
  nunca deixa um arquivo PHP quebrado no disco.
- Se um arquivo de classe gerado foi editado à mão desde a última geração, a próxima rodada detecta
  (hash) e **não sobrescreve silenciosamente** — avisa e pula.
- Campo que existe no catálogo mas saiu da sua spec atual (`fields`) é preservado verbatim na
  reescrita — nunca apagado silenciosamente.
- `request_file`/`request_method` (a IA pedindo outro arquivo/método de código durante a análise)
  são restritos a `allowed_base_path` — um path fora da raiz do projeto é recusado explicitamente,
  nunca lido.

## Testando

```bash
composer install
vendor/bin/phpunit
```

Testes rodam via [Orchestra Testbench](https://packages.tools/testbench), contra models de fixture
de um domínio genérico (blog) — sem depender de nenhuma app Laravel real.

## Licença

MIT.
