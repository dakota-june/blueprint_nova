<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintNovaResources\Fields;

use Illuminate\Support\Str;

/** Builds the relationship fields (BelongsTo, HasOne, HasMany, BelongsToMany) that point at other Nova resources. */
final class RelationFieldFactory
{
    private const COLLECTIONS = [
        'hasOne' => FieldTypes::HAS_ONE,
        'hasMany' => FieldTypes::HAS_MANY,
        'belongsToMany' => FieldTypes::BELONGS_TO_MANY,
    ];

    /** @param  string  $resourceFqcn  The Nova resource the field points at */
    public function belongsTo(RelationInfo $relation, string $resourceFqcn, FieldOptions $options, ?ColumnInfo $column): FieldDefinition
    {
        $field = new FieldDefinition(FieldTypes::BELONGS_TO, [
            PhpLiteral::string($options->label ?? Str::headline($relation->method)),
            PhpLiteral::string($relation->method),
            $this->reference($resourceFqcn),
        ]);

        $sortable = $options->sortable ?? (bool) config('blueprint_nova_resources.sortable', true);
        if ($sortable) {
            $field = $field->with('sortable');
        }

        $nullable = $column?->nullable ?? false;
        if ($nullable) {
            $field = $field->with('nullable');
        }

        if ($options->rules !== null) {
            if ($options->rules !== []) {
                $field = $field->with('rules', ...array_map(PhpLiteral::string(...), $options->rules));
            }
        } elseif (! $nullable && ($column === null || $column->default === null)) {
            $field = $field->with('rules', PhpLiteral::string('required'));
        }

        return $options->applyVisibility($field);
    }

    /** HasOne / HasMany / BelongsToMany. Nova shows these on the detail page by itself. */
    public function collection(RelationInfo $relation, string $resourceFqcn, FieldOptions $options): FieldDefinition
    {
        $field = new FieldDefinition(self::COLLECTIONS[$relation->type], [
            PhpLiteral::string($options->label ?? Str::headline($relation->method)),
            PhpLiteral::string($relation->method),
            $this->reference($resourceFqcn),
        ]);

        return $options->applyVisibility($field);
    }

    private function reference(string $resourceFqcn): string
    {
        return '{{ use:' . ltrim($resourceFqcn, '\\') . ' }}::class';
    }
}
