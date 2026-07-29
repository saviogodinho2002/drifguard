# Changelog

Todas as mudanças notáveis deste projeto são documentadas aqui. Formato baseado em
[Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/).

## [0.2.1] - 2026-07-29

### Added
- Badges do Packagist no README (versão, licença, PHP).
- `.gitattributes` — exclui `tests/`/`phpunit.xml` do pacote instalado via Composer.

## [0.2.0] - 2026-07-29

### Added
- `FieldSpec` fluente (`::string()`/`::enum()`/`::array()`/`::scopeClass()`) com validação eager,
  além do `fromArray()` já existente.
- `drifguard:doctor` — valida `config('drifguard.*')` (fields, paths, chave de API) sem chamar o LLM.
- `--dry-run` em `drifguard:analyze` (já existia em `apply`); `--json` em ambos os commands.
- Modo `rerun`: `drifguard:answer {model} {resposta}` responde uma pergunta pendente
  (`ask_question`) sem editar `questions.md` à mão, e a próxima análise já inclui esse model com a
  resposta injetada no contexto.
- `ModelDiscovery::extractRelevantMethods()` + `max_snippet_chars` — arquivo de apoio grande
  (controller/service) manda só os métodos relevantes, ou trunca em vez de ir inteiro sem teto.
- `drifguard:fields` / `drifguard:context:list` — introspecção da config ativa sem chamar o LLM.
- `allowed_base_path` — guarda de diretório em `request_file`, recusa leitura fora da raiz do
  projeto em vez de ler qualquer path que a IA peça.

## [0.1.0] - 2026-07-29

- Lançamento inicial: extração do `AnalyzeModels`/`ApplyModels` internos pra um pacote
  Laravel/Composer standalone, framework-agnostic (nenhum termo específico de domínio).
