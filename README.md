# Drifguard

Mantém um catálogo PHP curado dos seus models Eloquent sincronizado com o código real, usando um
LLM (configurável) num fluxo analyze → revisar → apply. Detecção de mudança via `git diff`,
extração estrutural sempre via **reflection real** (nunca por prosa da IA), e um passo de revisão
humana antes de qualquer escrita.

## Por quê

Documentar model manualmente desatualiza. Deixar uma IA reescrever tudo sem revisão arrisca perder
curadoria manual. Drifguard concilia os dois: fatos estruturais (tabela, campos, relações) sempre
vêm de reflection — nunca da IA; conhecimento de negócio (descrição, notas, e qualquer campo extra
que você definir) é proposto pela IA mas **nunca aplicado sem você revisar o diff antes**.

## Instalação

```bash
composer require saviogodinho2002/drifguard
php artisan vendor:publish --tag=drifguard-config
```

Configure `OPENROUTER_API_KEY` no seu `.env` (o cliente padrão usa OpenRouter + um model Claude —
troque em `config/drifguard.php` ou faça bind de outra implementação de `Contracts\AnalysisClient`
no seu próprio `ServiceProvider` se quiser outro provedor).

## Uso

```bash
php artisan drifguard:doctor           # valida a config inteira (sem chamar o LLM) — rode primeiro
php artisan drifguard:analyze --dry-run  # mostra modo/models/prévia, sem chamar o LLM
php artisan drifguard:analyze          # analisa o que mudou desde a última rodada (git diff)
php artisan drifguard:analyze --force  # reanalisa tudo
php artisan drifguard:apply --dry-run  # mostra o diff sem aplicar
php artisan drifguard:apply            # aplica (pede confirmação)
```

Perguntas pendentes (`ask_question`) — responda sem editar `questions.md` à mão:

```bash
php artisan drifguard:answer Post Sim, published_at nulo sempre significa rascunho.
php artisan drifguard:analyze          # próxima rodada já inclui Post com a resposta no contexto
```

Introspecção (sem chamar o LLM):

```bash
php artisan drifguard:fields                 # lista os campos extras (FieldSpec) configurados
php artisan drifguard:context:list           # mostra qual doc de contexto seria usado por model
php artisan drifguard:context:list --model=Post
```

### Uso por um agente de IA (CLI headless)

Todo command aceita `--json` (saída estruturada em vez de tabela/cores):

```bash
php artisan drifguard:analyze --dry-run --json    # prévia sem custo, parseável
php artisan drifguard:analyze --json              # roda e devolve {mode, models, proposals, questions}
php artisan drifguard:apply --json --dry-run       # {status: "dry_run", diff: [...]}
php artisan drifguard:apply --json --force         # aplica sem prompt interativo, {status: "applied", ...}
```

`drifguard:apply --json` **sem** `--force` nunca aplica — devolve `{status: "confirmation_required", diff}`
e sai com código de erro, pra uma execução headless nunca gravar mudança por engano só por ter
esquecido `--dry-run`.

## Customizando pro seu domínio

Tudo em `config/drifguard.php`, publicado no seu próprio projeto — edite livremente:

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
  use Saviogodinho2002\Drifguard\Support\FieldSpec;

  'fields' => [
      FieldSpec::string('gatilhos')->instructions('termos de busca'),
      FieldSpec::enum('classe_acesso', ['publico', 'restrito'])->instructions('...')->required(),
      FieldSpec::scopeClass('escopo_tenant')->instructions('...'),
  ],
  ```

  Rode `php artisan drifguard:doctor` depois de editar — valida cada `FieldSpec` (e os demais paths
  da config) sem chamar o LLM, apontando exatamente qual entrada está malformada.

- **`context_docs`** — aponte arquivos `.md` com regra de negócio que não dá pra inferir só lendo o
  código (motivo histórico, decisão de produto). Entra como contexto extra pra IA, nunca substitui
  o que reflection já determina. Veja o que está resolvido com `drifguard:context:list`.
- **`extra_prompt_rules`** — meta-instrução de formato/convenção específica do seu app (não é
  conhecimento de negócio por model — isso é `context_docs`).
- **`max_snippet_chars`** — teto de tamanho pra um arquivo de apoio (controller/service) sem método
  extraível — o pacote tenta primeiro extrair só os métodos que mencionam o model; se não achar
  nenhum, trunca no teto em vez de mandar o arquivo inteiro. O arquivo do próprio model nunca é
  truncado.
- **`allowed_base_path`** — diretório fora do qual a IA não pode ler arquivo via `request_file`
  durante a análise (default: raiz do projeto).
- **`discovery_paths`/`model_namespace`/`output_path`/`storage_path`** — todos os caminhos usados
  são configuráveis, nada fixo em `app/Models`.

## Perguntas pendentes e o modo `rerun`

Quando a IA usa `ask_question` em vez de propor uma atualização, o model fica pendente até alguém
responder — `php artisan drifguard:answer {model} {resposta}` (sem precisar editar `questions.md`).
A próxima `drifguard:analyze` (sem `--force`/`--model`) detecta automaticamente qualquer model com
pergunta respondida e o inclui na rodada — a resposta anterior entra no prompt como contexto humano
autoritativo, ao lado de `context_docs`.

## Segurança

- Nada é escrito sem um passo de `--dry-run`/confirmação (`drifguard:apply`).
- Campo do tipo `scope_class` roda checagem sintática (`php -l`) antes de gravar qualquer classe —
  nunca deixa um arquivo PHP quebrado no disco.
- Se um arquivo de classe gerado foi editado à mão desde a última geração, a próxima rodada detecta
  (hash) e **não sobrescreve silenciosamente** — avisa e pula.
- Campo que existe no catálogo mas saiu da sua spec atual (`fields`) é preservado verbatim na
  reescrita — nunca apagado silenciosamente.
- `request_file` (a IA pedindo outro arquivo de código durante a análise) é restrito a
  `allowed_base_path` — um path fora da raiz do projeto é recusado explicitamente, nunca lido.

## Testando

```bash
composer install
vendor/bin/phpunit
```

Testes rodam via [Orchestra Testbench](https://packages.tools/testbench), contra models de fixture
de um domínio genérico (blog) — sem depender de nenhuma app Laravel real.

## Licença

MIT.
