<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintNovaResources\Fields;

/**
 * The ordered list of field mappers. Higher priority runs first; the built-in ColumnFieldMapper is the
 * fallback at the lowest priority. Nothing is discovered: a mapper exists only if something registered it.
 */
final class FieldMapperRegistry
{
    /** @var array<int, array{0:int, 1:int, 2:FieldMapper}> priority, sequence, mapper */
    private array $mappers = [];

    private int $sequence = 0;

    public function __construct(?FieldMapper $fallback = null)
    {
        $this->register($fallback ?? new ColumnFieldMapper(), PHP_INT_MIN);
    }

    public function register(FieldMapper $mapper, int $priority = 0): void
    {
        $this->mappers[] = [$priority, $this->sequence++, $mapper];

        usort($this->mappers, static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
    }

    /** @return array<int, FieldDefinition>|null  null when no mapper could handle the column */
    public function map(FieldContext $context): ?array
    {
        foreach ($this->mappers as [, , $mapper]) {
            $result = $mapper->map($context);

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }
}
