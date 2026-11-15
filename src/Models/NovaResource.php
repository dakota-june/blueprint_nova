<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintNovaResources\Models;

use Blueprint\Contracts\Model as BlueprintModel;
use Dakotajune\BlueprintNovaResources\Fields\FieldOptions;

/**
 * Tree representation of one Nova resource from the draft's `nova:` block.
 */
final class NovaResource implements BlueprintModel
{
    /**
     * @param  string  $subNamespace  Namespace below the Nova namespace, e.g. "Admin" for the key `Admin\User`
     * @param  string  $modelName  Model reference as written in the draft (may include a sub-namespace)
     * @param  array<int, string>|null  $search  Searchable columns; null = derive them
     * @param  array<string, FieldOptions>  $fields  Per-field overrides, keyed by column (or relationship) name
     * @param  bool|array<int, string>  $relations  true = all, false = none, list = only those relationship methods
     */
    public function __construct(
        private readonly string $name,
        private readonly string $subNamespace,
        private readonly string $modelName,
        private readonly ?string $group,
        private readonly ?string $title,
        private readonly ?array $search,
        private readonly array $fields,
        private readonly bool|array $relations,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    /** Namespace relative to the application root namespace, e.g. "Nova" or "Nova\Admin". */
    public function namespace(): string
    {
        $base = trim((string) config('blueprint_nova_resources.namespace', 'Nova'), '\\');

        return trim($base . '\\' . $this->subNamespace, '\\');
    }

    /** e.g. "App\Nova" */
    public function fullyQualifiedNamespace(): string
    {
        return trim((string) config('blueprint.namespace', 'App') . '\\' . $this->namespace(), '\\');
    }

    /** e.g. "App\Nova\Invoice" */
    public function fullyQualifiedClassName(): string
    {
        return $this->fullyQualifiedNamespace() . '\\' . $this->name;
    }

    public function modelName(): string
    {
        return $this->modelName;
    }

    public function group(): ?string
    {
        return $this->group;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    /** @return array<int, string>|null */
    public function search(): ?array
    {
        return $this->search;
    }

    /** @return array<string, FieldOptions> */
    public function fieldOptions(): array
    {
        return $this->fields;
    }

    /** @return bool|array<int, string> */
    public function relations(): bool|array
    {
        return $this->relations;
    }
}
