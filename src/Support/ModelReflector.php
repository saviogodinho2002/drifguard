<?php

namespace Saviogodinho2002\Drifguard\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use ReflectionMethod;

/**
 * Extrai fatos estruturais de um model Eloquent via reflection real — nunca via prosa da IA.
 * tabela/campos/relacoes SEMPRE vêm daqui, mesmo que a IA proponha algo diferente (reflection
 * sempre vence, é o próprio motivo do pacote existir: parsear texto livre arrisca falso-negativo
 * silencioso, reflection não).
 *
 * Colunas de infraestrutura (id, timestamps, soft-delete) são excluídas de 'campos' por padrão —
 * ajustável via $colunasExcluidas se o app-host quiser outra lista.
 */
class ModelReflector
{
    private const COLUNAS_INFRA_PADRAO = ['id', 'created_at', 'updated_at', 'deleted_at'];

    public function __construct(
        private readonly string $modelNamespace,
        /** @var string[] */
        private readonly array $colunasExcluidas = self::COLUNAS_INFRA_PADRAO,
    ) {
    }

    /** @return array{tabela: string, campos: string, relacoes: string}|null null se a classe não existe/não é Model. */
    public function metadataFor(string $modelo): ?array
    {
        $class = $this->modelNamespace . '\\' . $modelo;
        if (!class_exists($class) || !is_subclass_of($class, Model::class)) {
            return null;
        }

        $instancia = new $class();

        return [
            'tabela'   => $instancia->getTable(),
            'campos'   => $this->camposFillable($instancia),
            'relacoes' => $this->relacoesResumo($class),
        ];
    }

    private function camposFillable(Model $instancia): string
    {
        $fillable = $instancia->getFillable();
        $filtrado = array_values(array_diff($fillable, $this->colunasExcluidas));
        return implode(', ', $filtrado);
    }

    private function relacoesResumo(string $class): string
    {
        $reflection = new ReflectionClass($class);
        $resumo     = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            if ($method->getNumberOfRequiredParameters() > 0 || $method->isStatic() || $method->isAbstract() || $method->isConstructor()) {
                continue;
            }
            $nome = $method->getName();
            if (str_starts_with($nome, '__')) {
                continue;
            }

            try {
                $resultado = (new $class())->$nome();
            } catch (\Throwable) {
                continue;
            }
            if (!($resultado instanceof Relation)) {
                continue;
            }

            $tipo = (new ReflectionClass($resultado))->getShortName();
            try {
                $relacionado = class_basename(get_class($resultado->getRelated()));
            } catch (\Throwable) {
                continue;
            }

            $resumo[] = "{$nome}: {$tipo}({$relacionado})";
        }

        return implode(', ', $resumo);
    }

    /**
     * Reflete UM model por vez, sob demanda — usado por consumidores que já sabem qual model
     * querem (ex: PromptBuilder montando o contexto de 1 análise), não pra varrer o app inteiro.
     * @return array<string, string> nome de relação lowercase => Model relacionado (nome curto)
     */
    public function relationTargets(string $modelo): array
    {
        $class = $this->modelNamespace . '\\' . $modelo;
        if (!class_exists($class) || !is_subclass_of($class, Model::class)) {
            return [];
        }

        $reflection = new ReflectionClass($class);
        $alvos      = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            if ($method->getNumberOfRequiredParameters() > 0 || $method->isStatic() || $method->isAbstract() || $method->isConstructor()) {
                continue;
            }
            $nome = $method->getName();
            if (str_starts_with($nome, '__')) {
                continue;
            }

            try {
                $resultado = (new $class())->$nome();
            } catch (\Throwable) {
                continue;
            }
            if (!($resultado instanceof Relation)) {
                continue;
            }

            try {
                $alvos[strtolower($nome)] = class_basename(get_class($resultado->getRelated()));
            } catch (\Throwable) {
                continue;
            }
        }

        return $alvos;
    }
}
