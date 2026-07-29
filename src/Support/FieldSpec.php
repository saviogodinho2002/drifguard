<?php

namespace Saviogodinho2002\Drifguard\Support;

use InvalidArgumentException;

/**
 * Descreve 1 campo "extra" do catálogo (além da base fixa: descricao, notas, e os reflection-only
 * tabela/campos/relacoes) — definido pelo app-host via config('drifguard.fields'), nunca hardcoded
 * no pacote. É isso que substitui o antigo TOOLS array fixo com escopo_fn/classe_acesso/gatilhos/etc.
 */
final class FieldSpec
{
    public const TYPE_STRING      = 'string';
    public const TYPE_ENUM        = 'enum';
    public const TYPE_ARRAY       = 'array';
    public const TYPE_SCOPE_CLASS = 'scope_class';

    private const VALID_TYPES = [self::TYPE_STRING, self::TYPE_ENUM, self::TYPE_ARRAY, self::TYPE_SCOPE_CLASS];

    /**
     * Contrato mecânico de formato pro tipo scope_class — definido uma vez aqui, usado tanto na
     * description da property de tool-calling (FieldSpec::toToolProperty()) quanto no system prompt
     * (PromptBuilder::regrasScopeClass()). Redundância intencional: provedores/models pesam
     * description-de-parâmetro e system-prompt de formas diferentes, então reforçar nos dois pontos
     * reduz a taxa de desvio de formato — não é duplicação de informação de negócio, é reforço de
     * uma única regra mecânica em 2 canais de atenção do modelo.
     */
    public const SCOPE_CLASS_FORMAT_CONTRACT = 'Retorne APENAS as statements do corpo do método, nada mais. '
        . 'Nunca inclua: cerca de código markdown (```php ou ```), a assinatura do método '
        . '(`public function apply(...)`), chaves de abertura/fechamento de classe ou método, nem '
        . 'declarações `use`. Use exatamente os nomes `$query` (Builder) e `$context` (o 2º parâmetro '
        . 'recebido) — nunca `$builder`/`$model`/outro nome, e nunca busque o usuário autenticado '
        . 'direto (auth()/Auth::user()/request()->user()), use sempre `$context`. Termine com uma '
        . 'instrução que retorne `$query` (ex: `return $query;` ou `return $query->where(...);`). Se '
        . 'precisar referenciar uma classe, use o nome totalmente qualificado (`\Namespace\Classe`), '
        . 'nunca um `use` no meio do corpo.';

    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $llmInstructions,
        /** @var string[] */
        public readonly array $enumValues = [],
        public readonly bool $required = false,
    ) {
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new InvalidArgumentException(
                "FieldSpec '{$name}': tipo '{$type}' inválido. Use um de: " . implode(', ', self::VALID_TYPES)
            );
        }
        if ($type === self::TYPE_ENUM && empty($enumValues)) {
            throw new InvalidArgumentException("FieldSpec '{$name}': tipo 'enum' exige 'enumValues' não-vazio.");
        }
    }

    /** @param array{name: string, type: string, llm_instructions?: string, enum_values?: string[], required?: bool} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            type: $data['type'],
            llmInstructions: $data['llm_instructions'] ?? '',
            enumValues: $data['enum_values'] ?? [],
            required: $data['required'] ?? false,
        );
    }

    // ── Factories fluentes ───────────────────────────────────────────────────
    // Alternativa ao array cru pra quem monta config('drifguard.fields') programaticamente (dev com
    // autocomplete, ou agente de IA editando o config) — valida no momento da construção (eager),
    // não só quando algum command roda.

    public static function string(string $name): self
    {
        return new self(name: $name, type: self::TYPE_STRING, llmInstructions: '');
    }

    /** @param string[] $enumValues */
    public static function enum(string $name, array $enumValues): self
    {
        return new self(name: $name, type: self::TYPE_ENUM, llmInstructions: '', enumValues: $enumValues);
    }

    public static function array(string $name): self
    {
        return new self(name: $name, type: self::TYPE_ARRAY, llmInstructions: '');
    }

    public static function scopeClass(string $name): self
    {
        return new self(name: $name, type: self::TYPE_SCOPE_CLASS, llmInstructions: '');
    }

    /** Retorna uma NOVA instância (imutável) com a instrução definida. */
    public function instructions(string $llmInstructions): self
    {
        return new self($this->name, $this->type, $llmInstructions, $this->enumValues, $this->required);
    }

    /** Retorna uma NOVA instância (imutável) marcada como obrigatória. */
    public function required(bool $required = true): self
    {
        return new self($this->name, $this->type, $this->llmInstructions, $this->enumValues, $required);
    }

    /** Converte pro formato de "property" de JSON Schema usado na definição de tool-calling. */
    public function toToolProperty(): array
    {
        $property = match ($this->type) {
            self::TYPE_ENUM => ['type' => 'string', 'enum' => $this->enumValues],
            self::TYPE_ARRAY => ['type' => 'array', 'items' => ['type' => 'string']],
            // scope_class é proposto como STRING (o corpo do método apply()) — o ScopeClassWriter
            // que decide, depois, gravar isso como arquivo de classe em vez de valor de config.
            self::TYPE_SCOPE_CLASS, self::TYPE_STRING => ['type' => 'string'],
        };

        $property['description'] = $this->type === self::TYPE_SCOPE_CLASS
            ? trim($this->llmInstructions . ' ' . self::SCOPE_CLASS_FORMAT_CONTRACT)
            : $this->llmInstructions;

        return $property;
    }
}
