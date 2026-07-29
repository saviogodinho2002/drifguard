<?php

namespace Saviogodinho2002\Drifguard\Contracts;

use Illuminate\Database\Eloquent\Builder;

/**
 * Contrato pras classes de escopo GERADAS por um FieldSpec do tipo 'scope_class'. Diferente de
 * guardar a regra como string de código (avaliada em runtime via eval()), o drifguard escreve um
 * arquivo .php de verdade implementando esta interface — revisável por git diff normal, navegável
 * por IDE, sem eval() de string nenhum.
 *
 * $context é deliberadamente `mixed` — cada app-host define o que faz sentido pro seu próprio
 * modelo de autorização (o usuário autenticado, um DTO de permissão, etc.); o pacote não assume
 * forma nenhuma pra ele.
 */
interface TenantScope
{
    public function apply(Builder $query, mixed $context): Builder;
}
