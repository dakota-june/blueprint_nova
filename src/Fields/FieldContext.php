<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintNovaResources\Fields;

/** Everything a FieldMapper may look at when mapping one column. Read-only. */
final class FieldContext
{
    public function __construct(
        public readonly string $resource,
        public readonly string $model,
        public readonly string $table,
        public readonly ColumnInfo $column,
        public readonly FieldOptions $options,
    ) {}
}
