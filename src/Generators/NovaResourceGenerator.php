<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintNovaResources\Generators;

use Blueprint\Blueprint;
use Blueprint\Contracts\Generator;
use Blueprint\Models\Model;
use Blueprint\Tree;
use Dakotajune\BlueprintNovaResources\Fields\ColumnInfo;
use Dakotajune\BlueprintNovaResources\Fields\FieldContext;
use Dakotajune\BlueprintNovaResources\Fields\FieldDefinition;
use Dakotajune\BlueprintNovaResources\Fields\FieldMapperRegistry;
use Dakotajune\BlueprintNovaResources\Fields\FieldOptions;
use Dakotajune\BlueprintNovaResources\Fields\FieldTypes;
use Dakotajune\BlueprintNovaResources\Fields\PhpLiteral;
use Dakotajune\BlueprintNovaResources\Fields\RelationFieldFactory;
use Dakotajune\BlueprintNovaResources\Fields\RelationInfo;
use Dakotajune\BlueprintNovaResources\Fields\RelationshipResolver;
use Dakotajune\BlueprintNovaResources\Models\NovaResource;
use Dakotajune\BlueprintNovaResources\Support\Manifest;
use Dakotajune\BlueprintNovaResources\Support\ManifestStore;
use Dakotajune\BlueprintNovaResources\Support\NoticeBag;
use Dakotajune\BlueprintNovaResources\Support\ResourceClassEditor;
use Dakotajune\BlueprintNovaResources\Support\ResourceMemberBuilder;
use Dakotajune\BlueprintNovaResources\Support\ResourceOptions;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class NovaResourceGenerator implements Generator
{
    private const BASE = 'Laravel\\Nova\\Resource';

    private readonly FieldMapperRegistry $mappers;

    private readonly NoticeBag $notices;

    private readonly ManifestStore $manifests;

    private readonly ResourceClassEditor $editor;

    private readonly RelationshipResolver $relationships;

    private readonly RelationFieldFactory $relationFields;

    // The Generator contract fixes the first parameter; the rest are optional collaborators.
    public function __construct(
        private readonly Filesystem $files,
        ?FieldMapperRegistry $mappers = null,
        ?NoticeBag $notices = null,
        ?ManifestStore $manifests = null,
        ?ResourceClassEditor $editor = null,
        ?RelationshipResolver $relationships = null,
        ?RelationFieldFactory $relationFields = null,
    ) {
        $this->mappers = $mappers ?? new FieldMapperRegistry();
        $this->notices = $notices ?? new NoticeBag();
        $this->manifests = $manifests ?? new ManifestStore($files);
        $this->editor = $editor ?? new ResourceClassEditor();
        $this->relationships = $relationships ?? new RelationshipResolver();
        $this->relationFields = $relationFields ?? new RelationFieldFactory();
    }

    /** @return array{created: array<int,string>, updated: array<int,string>} */
    public function output(Tree $tree): array
    {
        $output = ['created' => [], 'updated' => []];

        /** @var array<string, NovaResource> $resources */
        $resources = $tree->toArray()['nova'] ?? [];

        if ($resources === []) {
            // Like `.blueprint`, the manifest describes the latest build only. A build with no resources (the
            // `nova:` section removed, or `nova: false`) must not leave the last build's record behind, or a later
            // `blueprint:erase` would strip generated members from files this build never meant to touch.
            $this->manifests->save(new Manifest());

            return $output;
        }

        $previous = $this->manifests->load();
        $next = new Manifest();
        $catalog = $this->catalog($tree, $resources);

        foreach ($resources as $resource) {
            $path = $this->relativePath($resource->fullyQualifiedClassName());
            $existed = $this->files->exists(base_path($path));
            // We "own" the file (and may report it as created, so erase can delete it) if we made it, now or on an
            // earlier build. A file that was already there is only ever reported as updated.
            $created = ! $existed || ($previous->resource($path)['created'] ?? false);

            $seed = $this->write($tree, $resource, $path, $existed, $catalog)
                ?? ($existed ? ($previous->resource($path)['seed'] ?? null) : null);

            // Blueprint prints each entry as [label, path] and records the path in `.blueprint` (array_column(..., 1)).
            $output[$created ? 'created' : 'updated'][] = ['Nova resource', $path];
            $next->recordResource($path, $resource->name(), $created, $seed);

            if (! $existed) {
                $this->checkRegistration($resource);
            }
        }

        $this->manifests->save($next);

        return $output;
    }

    /** @return array<int, string> */
    public function types(): array
    {
        return ['nova'];
    }

    /**
     * Writes one resource. Returns the hash of the seed `fields()` when the file was created, otherwise null.
     *
     * @param  array<string, string>  $catalog  model class => Nova resource class, for every resource in the draft
     */
    private function write(Tree $tree, NovaResource $resource, string $path, bool $existed, array $catalog): ?string
    {
        $absolute = base_path($path);
        $base = $this->baseClass();
        $modelFqcn = $tree->fqcnForContext($resource->modelName());

        [$properties, $methods] = $this->members($tree, $resource, $modelFqcn, $catalog);

        $source = $existed ? $this->files->get($absolute) : $this->newFile($resource, $base);

        $result = $this->editor->update(
            $source,
            $resource->name(),
            $properties,
            $methods,
            $existed ? [] : [$base],
        );

        foreach ($result->notices as $notice) {
            $this->notices->add($notice);
        }

        // A resource that already existed keeps its own fields(). If that method never calls blueprintFields(), the
        // fields generated from the draft are written but never shown, which is easy to miss.
        if ($existed) {
            $fieldsMethod = $this->editor->methodSource($result->source, $resource->name(), 'fields');

            if ($fieldsMethod !== null && ! str_contains($fieldsMethod, 'blueprintFields')) {
                $this->notices->add(
                    "{$path}: fields() does not call blueprintFields(), so the fields generated from the draft are not shown. "
                    . 'Add `...$this->blueprintFields($request)` to the array it returns.',
                );
            }
        }

        if (! $existed) {
            $this->files->ensureDirectoryExists(dirname($absolute));
        }

        if (! $existed || $result->changed) {
            $this->files->put($absolute, $result->source);
        }

        return $existed ? null : $this->editor->methodHash($result->source, $resource->name(), 'fields');
    }

    /**
     * @param  array<string, string>  $catalog
     * @return array{0: array<string,string>, 1: array<string,string>}  properties, methods
     */
    private function members(Tree $tree, NovaResource $resource, string $modelFqcn, array $catalog): array
    {
        $model = $tree->modelForContext($resource->modelName());

        if ($model === null) {
            $this->notices->add(
                "{$resource->name()}: model [{$resource->modelName()}] is not in the draft, so only an ID field was generated. "
                . 'Describe the model under `models:`, or add its fields by hand in fields().',
            );

            $fields = [$this->idField()];
            $with = [];
            $title = $resource->title() ?? 'id';
            $search = $resource->search() ?? ['id'];
        } else {
            $columns = $this->columnInfos($model);
            [$fields, $with] = $this->fields($resource, $model, $columns, $tree, $catalog);
            $title = $this->title($resource, $model, $columns);
            $search = $this->search($resource, $model, $columns);
        }

        $properties = [
            'model' => ResourceMemberBuilder::property(
                'model',
                'The model the resource corresponds to.',
                '\\' . $modelFqcn . '::class',
                'class-string<\\' . $modelFqcn . '>',
            ),
            'title' => ResourceMemberBuilder::property(
                'title',
                'The single value that should be used to represent the resource when being displayed.',
                PhpLiteral::string($title),
                'string',
            ),
            'search' => ResourceMemberBuilder::property(
                'search',
                'The columns that should be searched.',
                PhpLiteral::list($search),
                'array<int, string>',
            ),
        ];

        if ($with !== []) {
            $properties['with'] = ResourceMemberBuilder::property(
                'with',
                'The relationships that should be eager loaded when performing an index query.',
                PhpLiteral::list($with),
                'array<int, string>',
            );
        }

        $group = $resource->group() ?? config('blueprint_nova_resources.default_group');
        if (is_string($group) && $group !== '') {
            $properties['group'] = ResourceMemberBuilder::property(
                'group',
                'The logical group associated with the resource.',
                PhpLiteral::string($group),
                'string',
            );
        }

        $methods = [
            'blueprintFields' => ResourceMemberBuilder::fields(array_map(
                static fn (FieldDefinition $field): string => $field->render(),
                $fields,
            )),
        ];

        // Resource-level options (navigation, labels, search, paging, ...): only values that differ from Nova's own
        // default are written. An option that left the draft is simply not wanted any more, so the editor deletes
        // the member it generated earlier.
        foreach (ResourceOptions::differing($resource->settings()) as $path => $value) {
            $option = ResourceOptions::definition($path);
            $literal = ResourceOptions::literal($path, $value);

            if ($option['kind'] === 'property') {
                $properties[$option['member']] = ResourceMemberBuilder::property(
                    $option['member'],
                    $option['doc'],
                    $literal,
                    ResourceOptions::varType($path),
                );
            } else {
                $methods[$option['member']] = ResourceMemberBuilder::staticMethod(
                    $option['member'],
                    $option['doc'],
                    'string',
                    $literal,
                );
            }
        }

        return [$properties, $methods];
    }

    /**
     * @param  array<string, ColumnInfo>  $columns
     * @param  array<string, string>  $catalog
     * @return array{0: array<int, FieldDefinition>, 1: array<int, string>}  fields, eager-loaded relationships
     */
    private function fields(NovaResource $resource, Model $model, array $columns, Tree $tree, array $catalog): array
    {
        $overrides = $resource->fieldOptions();
        $relations = $this->relationships->resolve($model, $tree);
        $allowed = $resource->relations();

        $this->validate($resource, $model, $columns, $relations);

        $belongsTo = [];
        $collections = [];

        foreach ($relations as $relation) {
            if (! in_array($relation->type, RelationshipResolver::SUPPORTED, true)) {
                $this->notices->add(
                    "{$resource->name()}: the {$relation->type} relationship [{$relation->method}] has no generated field yet; "
                    . 'add it by hand in fields().',
                );

                continue;
            }

            if ($allowed === false || (is_array($allowed) && ! in_array($relation->method, $allowed, true))) {
                continue;
            }

            if ($relation->type === 'belongsTo') {
                $belongsTo[$relation->foreignKey ?? ''] = $relation;
            } else {
                $collections[] = $relation;
            }
        }

        $fields = [];
        $with = [];
        $table = $model->tableName();

        foreach ($columns as $name => $info) {
            $options = $overrides[$name] ?? FieldOptions::none();

            if ($options->omit) {
                continue;
            }

            if ($options->as === null && isset($belongsTo[$name])) {
                $relation = $belongsTo[$name];
                // `fields: invoice: ...` (the relationship) and `fields: invoice_id: ...` (the column) both work.
                // A shortcut on the column (`novaLabel`, `novaCreate`) and an override by relationship name both apply;
                // where they disagree the relationship's own wins.
                $effective = isset($overrides[$relation->method]) ? $overrides[$relation->method]->orElse($options) : $options;

                if ($effective->omit) {
                    continue;
                }

                $related = $this->resourceFor($relation, $catalog);

                if ($related !== null) {
                    $fields[] = $this->relationFields->belongsTo($relation, $related, $effective, $info);
                    $with[] = $relation->method;

                    continue;
                }

                $this->notices->add(
                    "{$resource->name()}: [{$name}] is shown as a plain number because there is no Nova resource for "
                    . "{$relation->className}. Add `{$relation->className}: ~` under `nova:` to get a BelongsTo field.",
                );
            }

            $context = new FieldContext($resource->name(), $model->name(), $table, $info, $options);
            $mapped = $this->mappers->map($context);

            if ($mapped === null) {
                $this->notices->add(
                    "{$resource->name()}: column [{$name}] ({$info->type}) has no default Nova field and was left out. "
                    . "Choose one with `fields: {$name}: text`, or add it by hand in fields().",
                );

                continue;
            }

            array_push($fields, ...$mapped);
        }

        if ($model->usesTimestamps() && ($mode = config('blueprint_nova_resources.timestamps', 'detail')) !== false) {
            foreach (['created_at', 'updated_at'] as $name) {
                $options = $overrides[$name] ?? FieldOptions::none();

                if ($model->hasColumn($name) || $options->omit) {
                    continue;
                }

                $field = FieldDefinition::make(FieldTypes::DATE_TIME, $options->label ?? Str::headline($name), $name);
                $field = $mode === 'index' ? $field->with('sortable')->with('exceptOnForms') : $field->with('onlyOnDetail');
                $fields[] = $options->applyVisibility($field);
            }
        }

        foreach ($collections as $relation) {
            $options = $overrides[$relation->method] ?? FieldOptions::none();

            if ($options->omit) {
                continue;
            }

            $related = $this->resourceFor($relation, $catalog);

            if ($related === null) {
                $this->notices->add(
                    "{$resource->name()}: the {$relation->type} relationship [{$relation->method}] was skipped because there is "
                    . "no Nova resource for {$relation->className}. Add `{$relation->className}: ~` under `nova:` to include it.",
                );

                continue;
            }

            $fields[] = $this->relationFields->collection($relation, $related, $options);
        }

        return [$fields, array_values(array_unique($with))];
    }

    /**
     * @param  array<string, ColumnInfo>  $columns
     * @param  array<int, RelationInfo>  $relations
     */
    private function validate(NovaResource $resource, Model $model, array $columns, array $relations): void
    {
        $known = [
            ...array_keys($columns),
            'created_at', 'updated_at',
            ...array_map(static fn (RelationInfo $r): string => $r->method, $relations),
        ];

        $relationshipNames = array_map(static fn (RelationInfo $r): string => $r->method, $relations);

        foreach (array_keys($resource->fieldOptions()) as $name) {
            if (! in_array($name, $known, true)) {
                throw new InvalidArgumentException(
                    "Nova resource [{$resource->name()}] configures field [{$name}], which is not a column or relationship of {$model->name()}.",
                );
            }

            // Overrides are matched by name, so one key would restyle both. Refuse rather than guess which was meant.
            if (isset($columns[$name]) && in_array($name, $relationshipNames, true)) {
                throw new InvalidArgumentException(
                    "Nova resource [{$resource->name()}] configures field [{$name}], which is both a column and a relationship of "
                    . "{$model->name()}. Rename one of them; a model cannot reliably have both under the same name.",
                );
            }
        }

        // A relationship's field is always BelongsTo/HasMany/...; `as` and `map` only make sense on a column.
        foreach ($resource->fieldOptions() as $name => $options) {
            if (in_array($name, $relationshipNames, true) && ! isset($columns[$name]) && $options->as !== null) {
                $foreignKey = collect($relations)->first(static fn (RelationInfo $r): bool => $r->method === $name)?->foreignKey;
                $hint = $foreignKey !== null ? " To show the foreign key as another field type, configure the column [{$foreignKey}] instead." : '';

                throw new InvalidArgumentException(
                    "Nova resource [{$resource->name()}] field [{$name}] is a relationship, so `as` does not apply to it.{$hint}",
                );
            }
        }

        // The create button belongs to a BelongsTo field; nothing else has one.
        $belongsTo = [];
        foreach ($relations as $relation) {
            if ($relation->type === 'belongsTo') {
                $belongsTo[] = $relation->method;

                if ($relation->foreignKey !== null) {
                    $belongsTo[] = $relation->foreignKey;
                }
            }
        }

        foreach ($resource->fieldOptions() as $name => $options) {
            if ($options->create !== null && ! in_array($name, $belongsTo, true)) {
                throw new InvalidArgumentException(
                    "Nova resource [{$resource->name()}] field [{$name}]: `create` only applies to a belongsTo relationship, "
                    . 'written on its foreign key column or by the relationship name.',
                );
            }
        }

        foreach ([['title', $resource->title() === null ? [] : [$resource->title()]], ['search', $resource->search() ?? []]] as [$option, $names]) {
            foreach ($names as $name) {
                if (! isset($columns[$name])) {
                    throw new InvalidArgumentException(
                        "Nova resource [{$resource->name()}]: {$option} column [{$name}] is not a column of {$model->name()}.",
                    );
                }
            }
        }

        if (is_array($resource->relations())) {
            $methods = array_map(static fn (RelationInfo $r): string => $r->method, $relations);

            foreach ($resource->relations() as $name) {
                if (! in_array($name, $methods, true)) {
                    throw new InvalidArgumentException(
                        "Nova resource [{$resource->name()}]: relations lists [{$name}], which is not a relationship of {$model->name()}.",
                    );
                }
            }
        }
    }

    /** @return array<string, ColumnInfo> */
    private function columnInfos(Model $model): array
    {
        $primary = $model->usesPrimaryKey() ? $model->primaryKey() : null;
        $infos = [];

        foreach ($model->columns() as $name => $column) {
            $infos[$name] = ColumnInfo::fromBlueprint($column, $name === $primary);
        }

        return $infos;
    }

    /** @param  array<string, ColumnInfo>  $columns */
    private function title(NovaResource $resource, Model $model, array $columns): string
    {
        if ($resource->title() !== null) {
            return $resource->title();
        }

        foreach ((array) config('blueprint_nova_resources.title_candidates', []) as $candidate) {
            if (isset($columns[$candidate]) && in_array($columns[$candidate]->category(), ['string', 'text'], true)) {
                return (string) $candidate;
            }
        }

        return $model->usesPrimaryKey() ? $model->primaryKey() : 'id';
    }

    /**
     * @param  array<string, ColumnInfo>  $columns
     * @return array<int, string>
     */
    private function search(NovaResource $resource, Model $model, array $columns): array
    {
        if ($resource->search() !== null) {
            return $resource->search();
        }

        // `searchable` / `searchable:false` on a column adjusts the default: true always searches it (whatever its
        // type, the exclusion list or the limit), false never does. A draft's own `search:` skips all of this.
        $flags = $resource->columnSearch();
        $primary = $model->usesPrimaryKey() ? $model->primaryKey() : null;
        $search = $primary !== null && ($flags[$primary] ?? null) !== false ? [$primary] : [];
        $limit = max(0, (int) config('blueprint_nova_resources.search_limit', 5));
        $text = 0;

        foreach ($columns as $name => $info) {
            $name = (string) $name;
            $flag = $flags[$name] ?? null;

            if ($flag === true) {
                if (! in_array($name, $search, true)) {
                    $search[] = $name;
                }

                continue;
            }

            if ($flag === false || $text >= $limit) {
                continue;
            }

            $isText = in_array($info->type, ['string', 'char'], true) && ! $info->foreign && ! $info->primary;

            if ($isText && ! $this->excludedFromSearch($name)) {
                $search[] = $name;
                $text++;
            }
        }

        return $search;
    }

    /**
     * Columns that never go into the default $search: secrets, and columns that hold a format or an identifier
     * (`color`, `locale`, ...) rather than words. fnmatch() patterns from config, compared case-insensitively.
     * A config published before this option existed falls back to the secrets-only list it always had.
     */
    private function excludedFromSearch(string $column): bool
    {
        $patterns = (array) config('blueprint_nova_resources.search_exclude', ['*password*', '*secret*', '*token*', '*hash*']);

        foreach ($patterns as $pattern) {
            if (fnmatch(strtolower((string) $pattern), strtolower($column))) {
                return true;
            }
        }

        return false;
    }

    private function idField(): FieldDefinition
    {
        return (new FieldDefinition(FieldTypes::ID))->with('sortable');
    }

    /**
     * @param  array<string, NovaResource>  $resources
     * @return array<string, string>  model class => Nova resource class
     */
    private function catalog(Tree $tree, array $resources): array
    {
        $catalog = [];

        foreach ($resources as $resource) {
            $catalog[$tree->fqcnForContext($resource->modelName())] = $resource->fullyQualifiedClassName();
        }

        return $catalog;
    }

    /** The Nova resource for a related model: one from this draft, or a file that already exists. */
    private function resourceFor(RelationInfo $relation, array $catalog): ?string
    {
        if (isset($catalog[$relation->fqcn])) {
            return $catalog[$relation->fqcn];
        }

        $namespace = trim((string) config('blueprint.namespace', 'App') . '\\' . trim((string) config('blueprint_nova_resources.namespace', 'Nova'), '\\'), '\\');
        $sub = $relation->relatedModel !== null ? $relation->relatedModel->namespace() : '';
        $fqcn = $namespace . '\\' . ($sub !== '' ? $sub . '\\' : '') . $relation->className;

        return $this->files->exists(base_path($this->relativePath($fqcn))) ? $fqcn : null;
    }

    private function newFile(NovaResource $resource, string $base): string
    {
        return str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ base }}'],
            [$resource->fullyQualifiedNamespace(), $resource->name(), Str::afterLast($base, '\\')],
            $this->files->get($this->stubPath()),
        );
    }

    /** What new resources extend: the configured class, the app's own base resource if Nova installed one, or Nova's. */
    private function baseClass(): string
    {
        $configured = config('blueprint_nova_resources.base_resource');

        if (is_string($configured) && $configured !== '') {
            return ltrim($configured, '\\');
        }

        $own = trim((string) config('blueprint.namespace', 'App') . '\\' . trim((string) config('blueprint_nova_resources.namespace', 'Nova'), '\\'), '\\') . '\\Resource';

        return $this->files->exists(base_path($this->relativePath($own))) ? $own : self::BASE;
    }

    /**
     * After a resource is created: if the Nova service provider exists and does not register the whole directory,
     * say that the new resource may need registering. Nothing is edited.
     */
    private function checkRegistration(NovaResource $resource): void
    {
        $provider = base_path((string) config('blueprint_nova_resources.provider', 'app/Providers/NovaServiceProvider.php'));

        if (! $this->files->exists($provider)) {
            return;
        }

        $source = $this->files->get($provider);

        if (str_contains($source, 'resourcesIn(') || str_contains($source, $resource->name() . '::class')) {
            return;
        }

        // Nova's own provider class registers everything in app/Nova unless resources() is overridden here.
        if (preg_match('/extends\s+\\\\?(?:Laravel\\\\Nova\\\\)?NovaApplicationServiceProvider\b/', $source)
            && ! preg_match('/function\s+resources\s*\(/', $source)) {
            return;
        }

        $this->notices->add(
            "{$resource->fullyQualifiedClassName()} may need registering: {$this->relativeProvider($provider)} does not call "
            . 'Nova::resourcesIn() or list it in Nova::resources([...]).',
        );
    }

    private function relativeProvider(string $absolute): string
    {
        return ltrim(str_replace(str_replace('\\', '/', base_path()), '', str_replace('\\', '/', $absolute)), '/');
    }

    /** Path relative to the project root, built with Blueprint's own helpers. */
    private function relativePath(string $fullyQualifiedClassName): string
    {
        return Blueprint::appPath() . '/'
            . str_replace('\\', '/', Blueprint::relativeNamespace($fullyQualifiedClassName))
            . '.php';
    }

    private function stubPath(): string
    {
        $custom = base_path('stubs/blueprint/nova-resource.stub');

        return $this->files->exists($custom) ? $custom : dirname(__DIR__, 2) . '/stubs/nova-resource.stub';
    }
}
