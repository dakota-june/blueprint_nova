<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintNovaResources\Fields;

/** One relationship of a draft model, resolved the same way Blueprint's own model generator resolves it. */
final class RelationInfo
{
    public function __construct(
        public readonly string $type,           // belongsTo | hasOne | hasMany | belongsToMany | morphTo | ...
        public readonly string $method,         // the Eloquent method, e.g. "payments"
        public readonly string $className,      // related model short name, e.g. "Payment"
        public readonly string $fqcn,           // related model class, no leading backslash
        public readonly ?string $foreignKey,    // belongsTo only: the column on this model
        public readonly ?object $relatedModel,  // Blueprint's model object when the related model is in the draft
    ) {}
}
