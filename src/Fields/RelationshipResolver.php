<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintNovaResources\Fields;

use Blueprint\Models\Model;
use Blueprint\Tree;
use Illuminate\Support\Str;

/**
 * Works out, for every relationship Blueprint knows about on a model, the Eloquent method name, the related
 * model and (for belongsTo) the foreign key column. The rules mirror Blueprint's ModelGenerator::buildRelationships()
 * so the Nova field always points at a method that really exists on the generated model.
 */
final class RelationshipResolver
{
    /** Relationship types this package generates fields for. Others are reported and left to the developer. */
    public const SUPPORTED = ['belongsTo', 'hasOne', 'hasMany', 'belongsToMany'];

    private const PLURAL = ['hasMany', 'belongsToMany', 'morphMany', 'morphToMany', 'morphedByMany', 'hasManyThrough'];

    /** @return array<int, RelationInfo> */
    public function resolve(Model $model, Tree $tree): array
    {
        $resolved = [];

        foreach ($model->relationships() as $type => $references) {
            foreach ($references as $reference) {
                $resolved[] = $this->one($model, $tree, (string) $type, (string) $reference);
            }
        }

        return $resolved;
    }

    private function one(Model $model, Tree $tree, string $type, string $reference): RelationInfo
    {
        $isFqn = str_starts_with($reference, '\\');
        $isPivot = false;
        $customName = false;
        $class = null;
        $column = $reference;
        $method = $isFqn ? Str::afterLast($reference, '\\') : Str::beforeLast($reference, '_id');

        if (str_contains($reference, ':')) {
            [$foreign, $column] = explode(':', $reference, 2);

            if (str_starts_with($column, '&')) {
                $isPivot = true;
                $column = Str::after($column, '&');
                $method = $column;
            } else {
                $method = Str::beforeLast($column, '_id');
                $customName = true;
            }

            if (str_contains($foreign, '.')) {
                [$class] = explode('.', $foreign, 2);
                $method = $isFqn ? Str::lower(Str::afterLast($class, '\\')) : Str::lower($class);
                $customName = false;
            } else {
                $class = $foreign;
            }
        }

        if ($isFqn) {
            $fqcn = ltrim($class ?? $column, '\\');
            $className = Str::afterLast($fqcn, '\\');
            $related = $tree->modelForContext($className);
        } else {
            $className = Str::studly($class ?? $method);
            $related = $tree->modelForContext($className);
            $fqcn = $related !== null
                ? $related->fullyQualifiedClassName()
                : $model->fullyQualifiedNamespace() . '\\' . $className;
        }

        if ($type === 'morphTo') {
            $method = Str::lower($className);
        } elseif (in_array($type, self::PLURAL, true)) {
            $method = ($isPivot || $customName)
                ? Str::plural($isPivot ? $column : $method)
                : ($related !== null ? $related->pluralName() : Str::plural($method));
        }

        return new RelationInfo(
            type: $type,
            method: Str::camel($method),
            className: $className,
            fqcn: ltrim($fqcn, '\\'),
            foreignKey: $type === 'belongsTo' ? $this->foreignKey($reference, $column, $className, $isPivot) : null,
            relatedModel: $related,
        );
    }

    private function foreignKey(string $reference, string $column, string $className, bool $isPivot): string
    {
        if (str_contains($reference, ':') && ! $isPivot) {
            return $column;                                  // User:author_id  ->  author_id
        }

        if (! str_starts_with($reference, '\\') && str_ends_with($reference, '_id')) {
            return $reference;                               // invoice_id (inferred from the column)
        }

        return Str::snake($className) . '_id';               // User  ->  user_id
    }
}
