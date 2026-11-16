<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintNovaResources\Lexers;

use Blueprint\Contracts\Lexer;
use Dakotajune\BlueprintNovaResources\Fields\FieldOptions;
use Dakotajune\BlueprintNovaResources\Fields\FieldTypes;
use Dakotajune\BlueprintNovaResources\Models\NovaResource;
use Dakotajune\BlueprintNovaResources\Support\ResourceOptions;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Reads the top-level `nova:` section of a draft. Blueprint's own lexers ignore it, so nothing else needs
 * to be decorated or reordered.
 *
 *   nova:
 *     Invoice: ~                    # everything derived from the model's columns
 *     Payment:
 *       model: Payment              # optional, defaults to the key
 *       group: Billing
 *       title: number               # column shown as the resource's title
 *       search: [number, reference]
 *       relations: [invoice]        # true (default) | false | only these relationship methods
 *       navigation: false           # resource-level options: see ResourceOptions
 *       label: Bills
 *       singular: Bill
 *       globalSearch: { results: 10 }
 *       fields:
 *         status: badge             # change a column's field type
 *         notes:                    # or the long form
 *           as: markdown
 *           label: Internal notes
 *           hide: [index]
 *         secret: false             # leave a column out
 *
 * Also accepted: `nova: Invoice, Payment` and `nova: [Invoice, Payment]`; `nova: false` switches the package off.
 * Keys that start with a namespace (`Admin\User`) put the resource in a matching sub-namespace.
 */
final class NovaResourceLexer implements Lexer
{
    public const OPTIONS = ['model', 'group', 'title', 'search', 'fields', 'relations', ...ResourceOptions::KEYS];

    /** Planned, not built yet: rejected with a clear message instead of being silently ignored. */
    public const PLANNED = ['metrics', 'filters', 'actions', 'lenses', 'cards', 'dashboards', 'policy'];

    private const FIELD_KEYS = ['as', 'label', 'hide', 'readonly', 'sortable', 'rules', 'map'];

    /** Names Nova's own `nova:resource` command refuses (as far as the Nova API reference lists them). */
    private const RESERVED = ['card', 'cards', 'dashboard', 'dashboards', 'metric', 'metrics', 'script', 'scripts'];

    /** @return array{nova?: array<string, NovaResource>} */
    public function analyze(array $tokens): array
    {
        $entries = $this->entries($tokens);

        if ($entries === []) {
            return [];
        }

        $resources = [];

        foreach ($entries as $key => $definition) {
            if ($definition === false) {
                continue;                               // `Invoice: false` opts a model out, e.g. under auto_resources
            }

            $resource = $this->resource((string) $key, $definition);
            $fqcn = $resource->fullyQualifiedClassName();

            if (isset($resources[$fqcn])) {
                throw new InvalidArgumentException("Nova resource [{$resource->name()}] is defined more than once in the draft.");
            }

            $resources[$fqcn] = $resource;
        }

        return $resources === [] ? [] : ['nova' => $resources];
    }

    /** @return array<string, mixed> */
    private function entries(array $tokens): array
    {
        $section = $tokens['nova'] ?? null;

        if ($section === false) {
            return [];
        }

        $entries = [];

        if (is_string($section)) {
            foreach ($this->words($section) as $name) {
                $entries[$name] = null;
            }
        } elseif (is_array($section)) {
            foreach ($section as $key => $value) {
                if (is_string($key)) {
                    $entries[$key] = $value;
                } elseif (is_string($value)) {
                    foreach ($this->words($value) as $name) {
                        $entries[$name] = null;
                    }
                } else {
                    throw new InvalidArgumentException('The `nova:` section is unreadable: list resource names as `nova: [Invoice, Payment]`.');
                }
            }
        } elseif ($section !== null && $section !== true) {
            throw new InvalidArgumentException('The `nova:` section is unreadable: write `Invoice: ~` or `nova: [Invoice, Payment]`.');
        }

        if ((bool) config('blueprint_nova_resources.auto_resources', false)) {
            $declared = array_map(fn (string $k): string => $this->normalise($k), array_keys($entries));
            // Models that never get a resource unless the draft lists them under `nova:` itself.
            $except = array_map(
                fn (mixed $k): string => $this->normalise((string) $k),
                (array) config('blueprint_nova_resources.auto_resources_except', []),
            );

            foreach ($tokens['models'] ?? [] as $model => $definition) {
                $isPivot = is_array($definition) && ! empty($definition['meta']['pivot']);
                $name = $this->normalise((string) $model);

                if (! $isPivot && ! in_array($name, $declared, true) && ! in_array($name, $except, true)) {
                    $entries[(string) $model] = null;
                }
            }
        }

        return $entries;
    }

    private function resource(string $key, mixed $definition): NovaResource
    {
        [$sub, $name] = $this->nameParts($key);

        if (in_array(strtolower($name), self::RESERVED, true)) {
            throw new InvalidArgumentException("Nova does not allow a resource named [{$name}]; choose another name.");
        }

        $definition = match (true) {
            $definition === null, $definition === true => [],
            is_array($definition) => $definition,
            default => throw new InvalidArgumentException(
                "Nova resource [{$name}] must be written as `{$name}: ~` or with options ("
                . implode(', ', self::OPTIONS) . ').',
            ),
        };

        foreach (array_keys($definition) as $option) {
            if (in_array($option, self::PLANNED, true)) {
                throw new InvalidArgumentException("Nova resource [{$name}]: `{$option}` is not supported yet. Add it by hand to the generated class.");
            }

            if (! in_array($option, self::OPTIONS, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Nova resource [%s] has unknown option [%s]. Valid options: %s.',
                    $name,
                    $option,
                    implode(', ', self::OPTIONS),
                ));
            }
        }

        $model = isset($definition['model'])
            ? $this->modelName((string) $definition['model'])
            : ($sub !== '' ? $sub . '\\' . $name : $name);

        return new NovaResource(
            name: $name,
            subNamespace: $sub,
            modelName: $model,
            group: $this->group($name, $definition['group'] ?? null),
            title: $this->column($name, 'title', $definition['title'] ?? null),
            search: $this->search($name, $definition['search'] ?? null),
            fields: $this->fields($name, $definition['fields'] ?? null),
            relations: $this->relations($name, $definition['relations'] ?? null),
            settings: ResourceOptions::parse($name, $definition),
        );
    }

    private function group(string $resource, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Nova resource [{$resource}]: `group` must be text, e.g. `group: Billing`.");
        }

        return trim($value);
    }

    private function column(string $resource, string $option, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', trim($value))) {
            throw new InvalidArgumentException("Nova resource [{$resource}]: `{$option}` must be a column name.");
        }

        return trim($value);
    }

    /** @return array<int, string>|null */
    private function search(string $resource, mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $columns = is_string($value) ? $this->words($value) : (is_array($value) ? $value : null);

        if ($columns === null || $columns === []) {
            throw new InvalidArgumentException("Nova resource [{$resource}]: `search` must list column names, e.g. `search: [number, reference]`.");
        }

        $names = [];

        foreach ($columns as $column) {
            $name = is_string($column) ? $this->column($resource, 'search', $column) : null;

            if ($name === null) {
                throw new InvalidArgumentException("Nova resource [{$resource}]: `search` must list column names, e.g. `search: [number, reference]`.");
            }

            $names[] = $name;
        }

        return $names;
    }

    /** @return bool|array<int, string> */
    private function relations(string $resource, mixed $value): bool|array
    {
        if ($value === null || $value === true) {
            return true;
        }

        if ($value === false) {
            return false;
        }

        $names = is_string($value) ? $this->words($value) : (is_array($value) ? $value : null);

        if ($names === null || in_array(false, array_map('is_string', $names), true)) {
            throw new InvalidArgumentException("Nova resource [{$resource}]: `relations` must be true, false or a list of relationship names.");
        }

        return array_values(array_map('strval', $names));
    }

    /** @return array<string, FieldOptions> */
    private function fields(string $resource, mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException("Nova resource [{$resource}]: `fields` must map column names to a field type, e.g. `status: badge`.");
        }

        $fields = [];

        foreach ($value as $column => $spec) {
            $fields[(string) $column] = $this->field($resource, (string) $column, $spec);
        }

        return $fields;
    }

    private function field(string $resource, string $column, mixed $spec): FieldOptions
    {
        if ($spec === null || $spec === true) {
            return FieldOptions::none();
        }

        if ($spec === false) {
            return new FieldOptions(omit: true);
        }

        if (is_string($spec)) {
            return new FieldOptions(as: $this->type($resource, $column, $spec));
        }

        if (! is_array($spec)) {
            throw new InvalidArgumentException("Nova resource [{$resource}] field [{$column}] is unreadable.");
        }

        foreach (array_keys($spec) as $key) {
            if (! in_array($key, self::FIELD_KEYS, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Nova resource [%s] field [%s] has unknown option [%s]. Valid options: %s.',
                    $resource,
                    $column,
                    $key,
                    implode(', ', self::FIELD_KEYS),
                ));
            }
        }

        $as = isset($spec['as']) ? $this->type($resource, $column, (string) $spec['as']) : null;
        $map = $this->map($resource, $column, $spec['map'] ?? null);

        if ($map !== null && $as !== 'badge') {
            throw new InvalidArgumentException("Nova resource [{$resource}] field [{$column}]: `map` only applies with `as: badge`.");
        }

        return new FieldOptions(
            as: $as,
            label: isset($spec['label']) ? (string) $spec['label'] : null,
            hide: $this->hide($resource, $column, $spec['hide'] ?? null),
            readonly: $this->flag($resource, $column, 'readonly', $spec['readonly'] ?? null),
            sortable: $this->flag($resource, $column, 'sortable', $spec['sortable'] ?? null),
            rules: $this->rules($resource, $column, $spec['rules'] ?? null),
            map: $map,
        );
    }

    private function type(string $resource, string $column, string $type): string
    {
        $type = strtolower(trim($type));

        if (! in_array($type, FieldTypes::names(), true)) {
            throw new InvalidArgumentException(sprintf(
                'Nova resource [%s] field [%s] has unknown field type [%s]. Valid types: %s.',
                $resource,
                $column,
                $type,
                implode(', ', FieldTypes::names()),
            ));
        }

        return $type;
    }

    /** @return array<int, string> */
    private function hide(string $resource, string $column, mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $where = is_string($value) ? $this->words($value) : (is_array($value) ? array_map('strval', $value) : []);

        foreach ($where as $place) {
            if (! isset(FieldOptions::HIDE[$place])) {
                throw new InvalidArgumentException(sprintf(
                    'Nova resource [%s] field [%s]: cannot hide from [%s]. Use: %s.',
                    $resource,
                    $column,
                    $place,
                    implode(', ', array_keys(FieldOptions::HIDE)),
                ));
            }
        }

        return array_values(array_unique($where));
    }

    private function flag(string $resource, string $column, string $option, mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        $flag = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($flag === null) {
            throw new InvalidArgumentException("Nova resource [{$resource}] field [{$column}]: `{$option}` must be true or false.");
        }

        return $flag;
    }

    /** @return array<int, string>|null */
    private function rules(string $resource, string $column, mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $rules = is_string($value) ? array_values(array_filter(explode('|', $value), 'strlen')) : (is_array($value) ? $value : null);

        if ($rules === null || in_array(false, array_map('is_string', $rules), true)) {
            throw new InvalidArgumentException("Nova resource [{$resource}] field [{$column}]: `rules` must be a list of rule strings.");
        }

        return array_values($rules);
    }

    /** @return array<string, string>|null */
    private function map(string $resource, string $column, mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value) || $value === []) {
            throw new InvalidArgumentException("Nova resource [{$resource}] field [{$column}]: `map` must map values to a badge type.");
        }

        $map = [];

        foreach ($value as $key => $type) {
            if (! is_string($type) || ! in_array($type, FieldOptions::BADGE_TYPES, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Nova resource [%s] field [%s]: badge type for [%s] must be one of %s.',
                    $resource,
                    $column,
                    $key,
                    implode(', ', FieldOptions::BADGE_TYPES),
                ));
            }

            $map[(string) $key] = $type;
        }

        return $map;
    }

    /** @return array{0: string, 1: string}  [sub-namespace, class name] */
    private function nameParts(string $key): array
    {
        $segments = array_map(
            static fn (string $segment): string => Str::studly($segment),
            array_values(array_filter(preg_split('#[\\\\/]#', trim($key)) ?: [], 'strlen')),
        );

        if ($segments === []) {
            throw new InvalidArgumentException('A Nova resource in the draft has no name.');
        }

        $name = array_pop($segments);

        return [implode('\\', $segments), $name];
    }

    private function normalise(string $key): string
    {
        [$sub, $name] = $this->nameParts($key);

        return $sub === '' ? $name : $sub . '\\' . $name;
    }

    private function modelName(string $reference): string
    {
        return implode('\\', array_map(
            static fn (string $segment): string => Str::studly($segment),
            array_values(array_filter(preg_split('#[\\\\/]#', trim($reference)) ?: [], 'strlen')),
        ));
    }

    /** @return array<int, string> */
    private function words(string $value): array
    {
        return preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
