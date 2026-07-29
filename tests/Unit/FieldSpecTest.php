<?php

namespace Saviogodinho2002\Drifguard\Tests\Unit;

use InvalidArgumentException;
use Saviogodinho2002\Drifguard\Support\FieldSpec;
use Saviogodinho2002\Drifguard\Tests\TestCase;

class FieldSpecTest extends TestCase
{
    public function test_string_field_builds_string_tool_property(): void
    {
        $spec = new FieldSpec(name: 'gatilhos', type: FieldSpec::TYPE_STRING, llmInstructions: 'termos de busca');

        $property = $spec->toToolProperty();

        $this->assertSame('string', $property['type']);
        $this->assertSame('termos de busca', $property['description']);
    }

    public function test_enum_field_requires_enum_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FieldSpec(name: 'classe_acesso', type: FieldSpec::TYPE_ENUM, llmInstructions: '...', enumValues: []);
    }

    public function test_enum_field_builds_enum_tool_property(): void
    {
        $spec = new FieldSpec(
            name: 'classe_acesso',
            type: FieldSpec::TYPE_ENUM,
            llmInstructions: '...',
            enumValues: ['publico', 'restrito'],
        );

        $property = $spec->toToolProperty();

        $this->assertSame('string', $property['type']);
        $this->assertSame(['publico', 'restrito'], $property['enum']);
    }

    public function test_invalid_type_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FieldSpec(name: 'x', type: 'nao_existe', llmInstructions: '...');
    }

    public function test_scope_class_field_is_proposed_as_string(): void
    {
        $spec = new FieldSpec(name: 'escopo', type: FieldSpec::TYPE_SCOPE_CLASS, llmInstructions: '...');

        $this->assertSame('string', $spec->toToolProperty()['type']);
    }

    public function test_from_array(): void
    {
        $spec = FieldSpec::fromArray([
            'name'             => 'gatilhos',
            'type'             => 'string',
            'llm_instructions' => 'x',
            'required'         => true,
        ]);

        $this->assertSame('gatilhos', $spec->name);
        $this->assertTrue($spec->required);
    }

    // ── Factories fluentes ───────────────────────────────────────────────────

    public function test_fluent_string_matches_constructor_equivalent(): void
    {
        $fluente = FieldSpec::string('gatilhos')->instructions('termos de busca');
        $bruto   = new FieldSpec(name: 'gatilhos', type: FieldSpec::TYPE_STRING, llmInstructions: 'termos de busca');

        $this->assertEquals($bruto, $fluente);
    }

    public function test_fluent_enum_matches_constructor_equivalent(): void
    {
        $fluente = FieldSpec::enum('classe_acesso', ['publico', 'restrito'])
            ->instructions('...')
            ->required();
        $bruto = new FieldSpec(
            name: 'classe_acesso',
            type: FieldSpec::TYPE_ENUM,
            llmInstructions: '...',
            enumValues: ['publico', 'restrito'],
            required: true,
        );

        $this->assertEquals($bruto, $fluente);
    }

    public function test_fluent_array_and_scope_class_have_correct_types(): void
    {
        $this->assertSame(FieldSpec::TYPE_ARRAY, FieldSpec::array('tags')->type);
        $this->assertSame(FieldSpec::TYPE_SCOPE_CLASS, FieldSpec::scopeClass('escopo')->type);
    }

    public function test_fluent_methods_return_new_instance_not_mutate(): void
    {
        $base = FieldSpec::string('gatilhos');
        $comInstrucao = $base->instructions('x');

        $this->assertSame('', $base->llmInstructions, 'instância original não deve mudar (imutabilidade)');
        $this->assertSame('x', $comInstrucao->llmInstructions);
        $this->assertNotSame($base, $comInstrucao);
    }

    public function test_fluent_enum_still_validates_empty_values_eagerly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FieldSpec::enum('classe_acesso', []);
    }
}
