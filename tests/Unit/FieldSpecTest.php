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
}
