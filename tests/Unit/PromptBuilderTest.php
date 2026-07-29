<?php

namespace Saviogodinho2002\Drifguard\Tests\Unit;

use Saviogodinho2002\Drifguard\Support\FieldSpec;
use Saviogodinho2002\Drifguard\Support\PromptBuilder;
use Saviogodinho2002\Drifguard\Tests\TestCase;

class PromptBuilderTest extends TestCase
{
    public function test_tools_include_base_fields_plus_configured_fieldspecs(): void
    {
        $builder = new PromptBuilder([
            new FieldSpec(name: 'gatilhos', type: FieldSpec::TYPE_STRING, llmInstructions: 'termos de busca'),
            new FieldSpec(name: 'classe_acesso', type: FieldSpec::TYPE_ENUM, llmInstructions: '...', enumValues: ['publico', 'restrito']),
        ]);

        $tools      = $builder->buildTools();
        $properties = $tools[0]['function']['parameters']['properties'];

        $this->assertArrayHasKey('descricao', $properties); // base, sempre presente
        $this->assertArrayHasKey('notas', $properties);     // base, sempre presente
        $this->assertArrayHasKey('gatilhos', $properties);   // do FieldSpec do host
        $this->assertArrayHasKey('classe_acesso', $properties);
        $this->assertSame(['publico', 'restrito'], $properties['classe_acesso']['enum']);
    }

    public function test_zero_extra_fields_still_has_base_fields_only(): void
    {
        $builder = new PromptBuilder([]);

        $properties = $builder->buildTools()[0]['function']['parameters']['properties'];

        $this->assertSame(['descricao', 'notas'], array_keys($properties));
    }

    public function test_system_prompt_never_mentions_project_specific_business_terms(): void
    {
        $builder = new PromptBuilder([
            new FieldSpec(name: 'gatilhos', type: FieldSpec::TYPE_STRING, llmInstructions: 'termos de busca de um domínio genérico'),
        ]);

        $prompt = $builder->buildSystemPrompt();

        foreach (['AcmeCorp', 'LegacyModelName', 'legacyFieldName', 'InternalScopeClassName'] as $termoProibido) {
            $this->assertStringNotContainsString($termoProibido, $prompt);
        }
    }

    public function test_extra_prompt_rules_are_appended(): void
    {
        $builder = new PromptBuilder([], extraPromptRules: 'REGRA CUSTOMIZADA DO HOST: nunca faça X.');

        $this->assertStringContainsString('REGRA CUSTOMIZADA DO HOST', $builder->buildSystemPrompt());
    }

    public function test_build_messages_includes_reflected_metadata_as_authoritative(): void
    {
        $builder = new PromptBuilder([]);

        $mensagens = $builder->buildMessages(
            modelo: 'Post',
            snippets: ['app/Models/Post.php' => '<?php class Post {}'],
            contextDoc: null,
            reflectedMetadata: ['tabela' => 'posts', 'campos' => 'title, body', 'relacoes' => 'author: BelongsTo(Author)'],
        );

        $conteudoUsuario = $mensagens[1]['content'];
        $this->assertStringContainsString('tabela=posts', $conteudoUsuario);
        $this->assertStringContainsString('reflection, autoritativo', $conteudoUsuario);
    }
}
