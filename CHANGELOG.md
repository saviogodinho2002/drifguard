# Changelog

Todas as mudanças notáveis deste projeto são documentadas aqui. Formato baseado em
[Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/).

## [0.7.2] - 2026-07-31

Motivado por uma revisão geral do system prompt (`PromptBuilder::buildSystemPrompt()`) — a
estrutura em si (princípios invioláveis numerados + blocos de mecânica por tool) já é sólida, mas
achei uma lacuna real: a regra de prioridade entre contexto humano (`context_docs`/resposta anterior
via `driftguard:answer`) e reflection resolve qual fato ESTRUTURAL vence, mas nunca instruía o que
fazer quando o conteúdo humano parece CONTRADIZER o comportamento observado no código — isso só
aconteceria hoje por inferência do model sobre a regra de "nunca invente", não por instrução
dedicada, o que é dependente da capacidade do model e não confiável.

### Added
- Regra 5 no system prompt: quando o contexto humano (documento OU resposta anterior — as 2 fontes
  têm a mesma autoridade em `buildMessages()`, mas só `context_docs` era citado antes) parecer
  contradizer o código, a IA usa `ask_question` citando as duas versões em vez de resolver a
  divergência sozinha. Regra 4 (prioridade estrutural) generalizada pra citar as 2 fontes também.

## [0.7.1] - 2026-07-31

Motivado por uma revisão de segurança do modo `cli_harness` (v0.7.0): a única garantia contra o
harness escrever no código durante a exploração vinha inteiramente da CLI alvo (`--allowedTools`)
— e os presets `gemini`/`opencode` nem tinham isso confirmado. Corrigido com um bloqueio de escrita
no nível do sistema de arquivos, independente da CLI.

### Added
- **`ReadOnlyLock`** — trava `allowed_base_path` contra escrita (`chmod` removendo bits de escrita,
  restaurando o modo EXATO de cada arquivo depois, mesmo em erro/timeout) antes de
  `CliHarnessAnalysisClient` rodar o subprocesso. Camada principal de defesa durante a exploração —
  funciona igual pros 3 presets, não depende de a CLI ter allowlist de tool própria. Exclui sempre
  `storage/`, `bootstrap/cache/`, `vendor/`, `node_modules/`, `.git/`, mesmo dentro do path travado.
  Validado com probe real (arquivos de modos originais diferentes, 644 e 600, na mesma árvore):
  leitura/`scandir()` continuam funcionando normalmente enquanto travado, escrita/`unlink()` são
  bloqueados de verdade pelo SO, e a restauração é exata por arquivo, não um valor genérico.
  **Não funciona em Windows** — `chmod()` lá não impõe restrição real de diretório (só um atributo
  cosmético do NTFS) — detectado via `PHP_OS_FAMILY` e desabilitado automaticamente nesse caso, em
  vez de fingir uma garantia que não se sustenta.
- `llm.readonly_lock` em `config/driftguard.php` (default `true`).
- **Denylist de tools perigosas** em `CliHarnessAnalysisClient` — `Bash`/`Write`/`Edit`/
  `NotebookEdit` nunca entram na allowlist passada pra CLI, mesmo configuradas explicitamente em
  `harness_tools`. Antes, `harness_tools` era 100% configurável sem guarda nenhuma no código — só
  o valor default evitava essas tools.
- `driftguard:doctor` ganha 4 avisos novos (nível `WARN`, nunca falha o exit code) pro modo
  `cli_harness`: `readonly_lock` desabilitado no Windows (automático) ou desligado manualmente;
  preset ativo sem `tools_flag` confirmado (`gemini`/`opencode` hoje); `allowed_base_path` apontando
  pro projeto inteiro em vez de um escopo mais estreito.

### Changed
- README: nova seção explicando as 3 camadas de segurança durante a exploração (readonly_lock →
  allowlist da CLI → denylist de código) e por que usar o driftguard em vez de chamar a CLI direto
  (reflection-first, diff incremental, portão de revisão humana, guardas de `scope_class` — nenhuma
  dessas garantias sai da chamada à IA sozinha). Também removido hedging sobre processo de teste
  ("validado duas vezes", números de uma chamada específica) que não pertencia a um guia de uso
  vivo — esses números continuam nas entradas de changelog anteriores, onde já faziam sentido.

## [0.7.0] - 2026-07-30

Motivado por avaliar se um agente-CLI (Claude Code, Gemini CLI, opencode) rodando como subprocesso
pode substituir a API paga por token como provedor de análise, com qualidade equivalente — testado
de verdade antes de escrever qualquer código, nada assumido.

### Added
- **`CliHarnessAnalysisClient`** — nova implementação de `Contracts\AnalysisClient` que invoca um
  agente-CLI como subprocesso em vez de uma API stateless. A ponte com o contrato `chat(messages,
  tools) -> tool_calls` é feita internamente: serializa `messages`+`tools` num prompt único, instrui
  o CLI a responder com exatamente 1 JSON `{"tool": ..., "arguments": ...}`, e faz parse de acordo
  com o formato de resposta configurado (`single_json`/`json_stream`/`plain_text`) — o bloco JSON é
  extraído com casamento de chaves balanceado (`Support\BraceMatcher`, já usado em
  `ModelDiscovery`/`ScopeClassWriter`), tolerando prosa antes/depois em vez de exigir a resposta
  inteira pura. Roda com working directory fixado explicitamente (`Process::path()`) no diretório
  permitido. Qualquer falha (CLI ausente, timeout, saída não-JSON) degrada pra `tool_calls` vazio —
  nunca derruba o batch inteiro por 1 model falhar.
- `llm.driver => 'cli_harness'` em `config/driftguard.php`, com 3 presets prontos
  (`cli_harness_preset => 'claude' | 'gemini' | 'opencode'`) e override pontual de qualquer chave via
  `llm.cli_harness` — um `null` explícito num preset (ex: `gemini.dir_flag`, sem flag equivalente
  confirmada na doc) é sempre respeitado, nunca substituído pelo default de outro preset.
  **Validado duas vezes contra um model de produção real**: uma chamada de shell manual, e depois a
  classe `CliHarnessAnalysisClient` de ponta a ponta (não comando fake) — ambas com o preset
  `claude`. Resultado: JSON limpo, saída com regras de negócio numeradas e cross-referências que o
  harness descobriu sozinho via Grep (nenhum arquivo relacionado foi citado no prompt), qualidade
  nitidamente acima do que `extractSafeParts()`/`packWithinBudget()` conseguem com um orçamento fixo
  de contexto. Custo real: ~US$0.40 e ~70s pro model MENOR de uma amostra de 5 — mais caro por model
  do que a hipótese de "sai mais barato", SE cobrado por token (a resposta depende do plano do
  usuário ser assinatura de valor fixo com folga de uso, ou algo medido).
- Presets `gemini`/`opencode`: pesquisados via documentação oficial + issues públicas do GitHub, não
  instalados nem testados ao vivo nesta sessão. Achados que moldaram os presets: `--output-format
  json` do Gemini CLI está quebrado na versão atual
  ([gemini-cli#9009](https://github.com/google-gemini/gemini-cli/issues/9009)) — preset reflete isso
  com `response_format => 'plain_text'` e `cost_field => null`; opencode expõe custo num evento
  `step_finish` dentro de um stream de JSON (`response_format => 'json_stream'`) — um bug real onde
  esse evento podia não ser emitido a tempo já foi corrigido
  ([opencode#26855](https://github.com/anomalyco/opencode/issues/26855)).
- `--json` em `driftguard:doctor` e `driftguard:answer` — eram os únicos 2 commands sem saída
  estruturada, encontrado numa revisão de documentação (o README afirmava "todo command aceita
  `--json`", o que era falso até esta entrada). `doctor` devolve `{ok, checks: [{status, item,
  detalhe}, ...]}`; `answer` devolve `{status: "answered"|"not_found"|"missing_answer_text", model,
  question?}`.

## [0.6.1] - 2026-07-30

Estudei o [docudoodle](https://github.com/genericmilk/docudoodle) (gerador de docs Laravel via LLM)
procurando algo aproveitável — nada na parte de contexto/chunking (é arquivo inteiro + corte cego
por char count, o design atual do driftguard já é mais sofisticado), mas o mecanismo de detectar
`.md` órfão quando o source some (`array_diff` entre arquivos cacheados e encontrados) inspirou o
item abaixo, adaptado: o driftguard nunca apaga sozinho (o catálogo é curado à mão), só reporta.

### Added
- `driftguard:doctor` detecta entrada em `config/models.php` cujo model já não existe mais em
  `models_path` (renomeado, movido ou apagado) — nível `WARN`, nunca falha o exit code. Construído
  direto com `ModelDiscovery` (sem depender do singleton `ModelSyncService`, que constrói
  `FieldSpec` eagerly no factory do container — um field malformado já quebraria a resolução antes
  do próprio `doctor` conseguir reportar isso com seu try/catch de sempre).

## [0.6.0] - 2026-07-30

Motivado por 2 perguntas sobre o que ainda falta quando o orçamento por model não é suficiente: se
a IA consegue pedir de volta um método específico que foi descartado, e se existe um jeito de pagar
o custo total numa rodada só quando vale a pena (auditoria, 1ª análise cuidadosa de um model
crítico).

### Fixed
- Tipo de retorno de **interseção** (`): Countable&ArrayAccess`, PHP 8.1+) não era reconhecido pelo
  regex compartilhado de extração de método (`ModelDiscovery::extractMethodBodies()`/
  `extractRelevantMethods()`) — um método com essa assinatura desaparecia silenciosamente de
  QUALQUER extração (`extractSafeParts`, `extractNamedMethods`, `extractRelevantMethods`), não só
  do recurso novo abaixo. Achado testando exaustivamente as formas de assinatura possíveis (sem
  retorno, escalar, nulável, união, array/iterable, namespaced, interseção, estático, visibilidade
  implícita/explícita) antes de escrever `request_method`. Regressão checada contra os 5 models
  reais usados nesta sessão — contagem de métodos extraídos idêntica antes/depois, confirma que o
  fix é estritamente aditivo.
- `packWithinBudget()` (v0.5.1) passa a **nomear** os métodos descartados no aviso (antes só dizia
  "N método(s) descartado(s)", sem dizer quais) — sem os nomes exatos, não haveria como a IA saber
  o que pedir de volta via `request_method`.

### Added
- **`request_method`** — nova tool de tool-calling: pede o corpo de um ou mais métodos específicos
  que não vieram completos no contexto, sem precisar do arquivo inteiro (antes só existia
  `request_file`, granularidade de arquivo). Aceita uma LISTA (`requests: [{path, method}, ...]`) —
  pode pedir vários métodos, inclusive de arquivos diferentes, numa única chamada, já que o número
  de rodadas de análise por model é limitado (`MAX_ANALYSIS_ITER = 4`, compartilhado entre todas as
  tools). Método pedido que não existe no arquivo nunca falha silenciosamente — vira mensagem
  explícita ("método(s) não encontrado(s)"). Mesma guarda de `allowed_base_path` que `request_file`
  já aplica. Novo `PromptBuilder::regrasRequestMethod()` (sempre incluída no system prompt, não só
  quando há campo `scope_class`) instrui a IA a usar os nomes exatos do aviso de descarte e a
  batelar pedidos em vez de 1 chamada por método.
- **`--full`** em `driftguard:analyze` — ignora `max_snippet_chars`/`max_total_snippet_chars`/
  `max_supporting_files` só nesta rodada, mandando cada model (e seus arquivos de apoio) inteiro,
  sem extração/truncamento/corte. Não muda QUAIS models são analisados (isso continua vindo de
  `--force`/`--model`/diff normal) — só QUANTO CONTEÚDO de cada um entra. Composição confirmada
  contra `AnalyzeModelsCommand::handle()`: funciona igual sobre o modo `diff` padrão (reanalisa só
  o que mudou, mas sem cortar nada) ou combinado com `--force` (tudo, sem corte nenhum — o caso de
  auditoria).

## [0.5.1] - 2026-07-30

Depois do `extractSafeParts()` (v0.5.0), medi contra os mesmos 5 models reais o que acontecia
DEPOIS da extração: o truncamento por posição bruta (`mb_substr`) que rodava em seguida ainda
cortava a maior parte do conteúdo já reduzido, e no meio de um método, sem critério — só uns
13-35% do arquivo original chegava na IA.

### Fixed
- `ModelDiscovery::packWithinBudget()` (novo, movido de dentro de `ModelSyncService` — lógica pura
  de empacotamento, sem depender de nada do serviço) substitui o truncamento por posição: nunca
  corta um método no meio. Testei 2 estratégias antes de fechar (menor-primeiro "amplitude" e
  maior-primeiro "profundidade") — a de manter o maior primeiro e fazer *backfill* dos menores no
  espaço que sobra venceu nas 5 vezes na medição real de bytes retidos, incluindo ganho de
  amplitude de bônus quando sobra espaço. Se um único método já estoura o orçamento sozinho, entra
  inteiro mesmo assim — nunca fica vazio, nunca corta no meio.

Resultado medido nos mesmos 5 models: ganhos de +0.2 a +28.2 pontos percentuais na retenção do
arquivo original chegando na IA (faixa foi de 13%-58% para 15%-58%) — sem
regressão em nenhum.

## [0.5.0] - 2026-07-30

Motivado por relato de uso real: alguns models (segundo projeto externo) são grandes o bastante
pra o arquivo inteiro do model virar ruído/custo desnecessário no contexto enviado ao LLM.
Validado contra 5 models reais e grandes de uma aplicação em produção antes de fechar o design — uma
versão mais agressiva de extração (só relação/scope/booted/accessor) chegava a reduzir 99%, mas
cortava método de negócio público real num dos models testados; a versão que entrou é a que nunca
perde nada, com redução real porém mais modesta (1.6%-35.5% nos 5 models testados).

### Added
- `ModelDiscovery::extractSafeParts()` — arquivo do próprio model, quando maior que
  `max_snippet_chars`, mantém todo método PÚBLICO (superfície de negócio) + `boot()`/`booted()` +
  `scopeXxx()`/accessor mesmo se não-público; remove só uma denylist curta de overrides do Eloquent
  puramente boilerplate (`setKeysForSaveQuery` e afins). Nunca corta método de negócio público.
- `Support\ModelIndex` — índice persistido por model (`storage_path/index/{Model}.json`,
  compartilhado via git igual `context.json`) guardando os métodos já classificados como relevantes
  e o hash do conteúdo — evita reclassificar tudo de novo quando o arquivo não mudou desde a última
  rodada; auditável (dá pra abrir o JSON e ver exatamente o que foi considerado relevante).
- `max_total_snippet_chars` (default 60000) — orçamento combinado somando TODOS os snippets de 1
  model (arquivo do model + arquivos de apoio); se estourar, descarta arquivo de apoio (nunca o do
  model) começando pelos de menor conteúdo extraído. Espelha o `MAX_TOTAL_CHARS` do sistema
  original de onde o driftguard foi extraído.
- `max_supporting_files` (default 5) — teto de quantos arquivos de apoio são coletados por model.
  Espelha o `MAX_RELATED_FILES` do original.

## [0.4.0] - 2026-07-29

### Changed
- **Breaking: pacote renomeado de `drifguard` pra `driftguard`** (corrige o "t" que faltava de
  "drift"). Afeta tudo: nome do pacote Composer (`saviogodinho2002/driftguard`), namespace PHP
  (`Saviogodinho2002\DriftGuard\`), nome da service provider (`DriftGuardServiceProvider`), todos
  os 7 comandos artisan (`driftguard:analyze`, `driftguard:apply`, `driftguard:doctor`,
  `driftguard:init`, `driftguard:answer`, `driftguard:fields`, `driftguard:context:list`), arquivo
  e chave de config (`config/driftguard.php`, `config('driftguard.*')`), tag de publish
  (`driftguard-config`), e os defaults de path/namespace de `scope_class` (`app_path('DriftGuard/Scopes')`,
  `'App\\DriftGuard\\Scopes'`). Quem já instalou sob o nome antigo precisa: `composer remove
  saviogodinho2002/drifguard && composer require saviogodinho2002/driftguard`, republicar o config,
  atualizar qualquer script/CI que chame `drifguard:*`, e regenerar classes `scope_class` existentes
  (`driftguard:apply --force`) — o pacote não migra automaticamente nada gerado sob o nome antigo.

## [0.3.1] - 2026-07-29

Motivado por outro relatório real de produção: 2 fields `scope_class` no mesmo model
(`escopo_projeto` + `escopo_membro` em `Contract`) geravam o MESMO arquivo/classe — o 2º field
escrito sobrescrevia silenciosamente a lógica do 1º, sem erro, sem warning, sem confirmação.
`config/models.php` ficava com as 2 chaves corretas na aparência, mas ambas apontando pro mesmo FQCN.

### Fixed
- **Breaking**: `ScopeClassWriter::classNameFor()`/`classPathFor()`/`fqcnFor()`/`write()` agora
  incluem o nome do FIELD, não só do model — o nome da classe gerada passa de `{Model}Scope` pra
  `{Model}{FieldStudly}Scope` (ex: `ContractEscopoMembroScope`), **mesmo pra quem só tem 1 field
  `scope_class`** (sem colisão nenhuma) — pacote ainda é 0.x, mudança de nome aceitável via minor
  bump. Se você já tem classes geradas sob o nome antigo: rode `driftguard:apply --force` depois de
  atualizar pra regerar sob o nome novo, e apague manualmente o arquivo antigo órfão (o pacote não
  deleta arquivo que ele não está escrevendo na rodada atual).

### Added
- `driftguard:doctor` detecta nome de `FieldSpec` duplicado em `config('driftguard.fields')` — antes
  disso, um nome repetido já sobrescrevia silenciosamente o field anterior no schema de
  tool-calling (`PromptBuilder::buildTools()`), independente de ser `scope_class` ou não.

## [0.3.0] - 2026-07-29

Motivado por um relatório real de produção (segundo projeto externo): 13/45 (~29%) das gerações de
`scope_class` vinham em formato inválido — todas corretamente rejeitadas pelo `php -l` já existente
(zero arquivo quebrado gravado), mas com taxa de rejeição alta demais pra ser prático.

### Added
- `ScopeClassWriter::sanitize()` — remove cerca de código markdown, `use` solto, e assinatura de
  método reincluída de um corpo de `scope_class` proposto, antes de qualquer checagem.
- **Checagem semântica nova** (`ScopeClassWriter`, roda sempre, depois de sanitizar): rejeita corpo
  que não referencia `$query`, que usa `$builder`/`$model` (nomes errados observados no caso real),
  ou que chama `auth()->user()`/`Auth::user()`/`request()->user()` direto em vez de usar `$context`.
  **Importante**: sanitizar sozinho tornaria o caso real relatado sintaticamente válido (`php -l`
  passaria) mas semanticamente quebrado — uma classe de escopo que compila mas não restringe nada,
  vazamento cross-tenant silencioso. A checagem semântica fecha esse risco; novo status
  `semantic_check_failed` (ao lado de `syntax_error`) reflete isso na API de `ScopeClassWriter::write()`.
- Retry único em tempo de análise (`ModelSyncService::loopAnalise()`): se um campo `scope_class`
  falhar sanitização+checagem semântica, a IA recebe o erro específico + o contrato de formato e
  tem 1 chance de corrigir antes da proposta ser aceita — reaproveita o loop de tool-calling
  existente, sem retry "frio" fora de contexto.
- `FieldSpec::SCOPE_CLASS_FORMAT_CONTRACT` — contrato mecânico de formato reforçado tanto na
  description da property de tool-calling quanto no system prompt (`PromptBuilder::regrasScopeClass()`).
- Diagnóstico: erros de `scope_class` agora incluem o nome do model inline na mensagem e
  `raw_body_preview` (conteúdo bruto truncado) no array de retorno — útil em `--json` pra triagem
  em lote.
- `Support\BraceMatcher` — casamento de chaves balanceado extraído de `ModelDiscovery` pra um
  helper compartilhado (usado agora também pelo sanitizador de `scope_class`).
- `driftguard:init` — estabelece o baseline de `context.json` (`last_commit_hash` = HEAD atual) sem
  chamar o LLM, pra quem não quer pagar o custo de `driftguard:analyze --force` só pra fazer o
  arquivo existir. Nunca sobrescreve um `context.json` já existente sem `--force`.

### Changed
- **`storage_path` default mudou de `storage_path('app/driftguard')` pra `base_path('driftguard')`**
  — o `.gitignore` padrão do Laravel exclui `storage/`, então o default antigo deixava
  `context.json` (last_commit_hash, perguntas pendentes/respondidas) fora do git: cada dev/agente
  via um estado local diferente, sem saber o que outro já analisou ou respondeu. Bate agora com o
  comportamento do sistema original de onde este pacote foi extraído (`base_path('ai-models-sync')`,
  rastreado no git). Quem já está usando o pacote e quer manter o comportamento antigo pode apontar
  `storage_path` de volta pra `storage_path('app/driftguard')` e adicionar ao próprio `.gitignore`.

## [0.2.2] - 2026-07-29

### Added
- Documentação de como escrever uma boa `llm_instructions` pro tipo `scope_class` — o que já entra
  de graça no contexto (arquivo do próprio model) vs o que precisa ir em `supporting_paths` (ex:
  middleware de tenant) vs o risco de instruir "replique o que o controller faz" quando o
  filtro é inconsistente entre pontos de entrada.

## [0.2.1] - 2026-07-29

### Added
- Badges do Packagist no README (versão, licença, PHP).
- `.gitattributes` — exclui `tests/`/`phpunit.xml` do pacote instalado via Composer.

## [0.2.0] - 2026-07-29

### Added
- `FieldSpec` fluente (`::string()`/`::enum()`/`::array()`/`::scopeClass()`) com validação eager,
  além do `fromArray()` já existente.
- `driftguard:doctor` — valida `config('driftguard.*')` (fields, paths, chave de API) sem chamar o LLM.
- `--dry-run` em `driftguard:analyze` (já existia em `apply`); `--json` em ambos os commands.
- Modo `rerun`: `driftguard:answer {model} {resposta}` responde uma pergunta pendente
  (`ask_question`) sem editar `questions.md` à mão, e a próxima análise já inclui esse model com a
  resposta injetada no contexto.
- `ModelDiscovery::extractRelevantMethods()` + `max_snippet_chars` — arquivo de apoio grande
  (controller/service) manda só os métodos relevantes, ou trunca em vez de ir inteiro sem teto.
- `driftguard:fields` / `driftguard:context:list` — introspecção da config ativa sem chamar o LLM.
- `allowed_base_path` — guarda de diretório em `request_file`, recusa leitura fora da raiz do
  projeto em vez de ler qualquer path que a IA peça.

## [0.1.0] - 2026-07-29

- Lançamento inicial: extração do `AnalyzeModels`/`ApplyModels` internos pra um pacote
  Laravel/Composer standalone, domain-agnostic (nenhum termo específico de domínio).
