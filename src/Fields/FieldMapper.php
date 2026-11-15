<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintNovaResources\Fields;

/**
 * Turns one column into Nova field(s). Register extra mappers from your own service provider:
 *
 *     $this->app->make(FieldMapperRegistry::class)->register(new MoneyFieldMapper(), priority: 10);
 *
 * Return null to hand the column to the next mapper, an empty array to deliberately leave it out of the
 * resource, or one or more definitions to use them.
 */
interface FieldMapper
{
    /** @return array<int, FieldDefinition>|null */
    public function map(FieldContext $context): ?array;
}
