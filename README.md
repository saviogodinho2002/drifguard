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
php artisan drifguard:analyze          # analisa o que mudou desde a última rodada (git diff)
php artisan drifguard:analyze --force  # reanalisa tudo
php artisan drifguard:apply --dry-run  # mostra o diff sem aplicar
php artisan drifguard:apply            # aplica (pede confirmação)
```

## Customizando pro seu domínio

Tudo em `config/drifguard.php`, publicado no seu próprio projeto — edite livremente:

- **`fields`** — campos extras do seu domínio (além da base `descricao`/`notas`/`tabela`/`campos`/`relacoes`
  que o pacote já cobre). Tipos: `string`, `enum`, `array`, ou `scope_class` (gera um arquivo `.php`
  de classe de escopo de tenant de verdade, implementando `Contracts\TenantScope` — nunca guarda
  código como string pra `eval()` depois).
- **`context_docs`** — aponte arquivos `.md` com regra de negócio que não dá pra inferir só lendo o
  código (motivo histórico, decisão de produto). Entra como contexto extra pra IA, nunca substitui
  o que reflection já determina.
- **`extra_prompt_rules`** — meta-instrução de formato/convenção específica do seu app (não é
  conhecimento de negócio por model — isso é `context_docs`).
- **`discovery_paths`/`model_namespace`/`output_path`/`storage_path`** — todos os caminhos usados
  são configuráveis, nada fixo em `app/Models`.

## Segurança

- Nada é escrito sem um passo de `--dry-run`/confirmação (`drifguard:apply`).
- Campo do tipo `scope_class` roda checagem sintática (`php -l`) antes de gravar qualquer classe —
  nunca deixa um arquivo PHP quebrado no disco.
- Se um arquivo de classe gerado foi editado à mão desde a última geração, a próxima rodada detecta
  (hash) e **não sobrescreve silenciosamente** — avisa e pula.
- Campo que existe no catálogo mas saiu da sua spec atual (`fields`) é preservado verbatim na
  reescrita — nunca apagado silenciosamente.

## Testando

```bash
composer install
vendor/bin/phpunit
```

Testes rodam via [Orchestra Testbench](https://packages.tools/testbench), contra models de fixture
de um domínio genérico (blog) — sem depender de nenhuma app Laravel real.

## Licença

MIT.
