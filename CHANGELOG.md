# Changelog

Todas as mudanças notáveis deste projeto são documentadas aqui. Formato baseado em
[Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/).

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
  os 8 comandos artisan (`driftguard:analyze`, `driftguard:apply`, `driftguard:doctor`,
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
  Laravel/Composer standalone, framework-agnostic (nenhum termo específico de domínio).
