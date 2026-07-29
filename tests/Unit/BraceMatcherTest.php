<?php

namespace Saviogodinho2002\DriftGuard\Tests\Unit;

use Saviogodinho2002\DriftGuard\Support\BraceMatcher;
use Saviogodinho2002\DriftGuard\Tests\TestCase;

class BraceMatcherTest extends TestCase
{
    public function test_matches_simple_brace_pair(): void
    {
        $conteudo = 'function x() { return 1; }';
        $abertura = strpos($conteudo, '{');

        $this->assertSame(strrpos($conteudo, '}'), BraceMatcher::fechamentoDe($conteudo, $abertura));
    }

    public function test_matches_across_nested_braces(): void
    {
        $conteudo = 'function x() { if (true) { foreach ([1] as $i) { doSomething(); } } }';
        $abertura = strpos($conteudo, '{');

        $this->assertSame(strrpos($conteudo, '}'), BraceMatcher::fechamentoDe($conteudo, $abertura));
    }

    public function test_returns_null_when_unbalanced(): void
    {
        $conteudo = 'function x() { return 1;';
        $abertura = strpos($conteudo, '{');

        $this->assertNull(BraceMatcher::fechamentoDe($conteudo, $abertura));
    }
}
