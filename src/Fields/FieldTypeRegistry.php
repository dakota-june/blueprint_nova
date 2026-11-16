<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintNovaResources\Fields;

use InvalidArgumentException;

/**
 * The table that says which Nova field a column becomes: "a string column is a Text field, a text column is a
 * Textarea, a column called `email` is an Email field".
 *
 * Three kinds of rule, most specific first:
 *
 *   1. Names:  a column whose name matches a pattern (`description`, `*_url`).     Only text and string columns.
 *   2. Types:  a Blueprint type (`longText`, `char`) or, failing that, its category
 *              (`string`, `text`, `integer`, `decimal`, `json`, `boolean`, `date`, `datetime`, `enum`).
 *   3. The built-in defaults below.
 *
 * Within a kind, rules registered in code beat the config, and a rule registered later beats an earlier one.
 * A draft's own `fields:` override always beats all of this, and the primary key, housekeeping columns
 * (timestamps, remember_token, softDeletes) and foreign keys are never remapped.
 *
 * Config (config/blueprint_nova_resources.php):
 *
 *     'field_types' => ['text' => 'markdown'],
 *     'name_hints'  => ['markdown' => ['description', 'body']],
 *
 * Code, from a service provider:
 *
 *     $this->app->make(FieldTypeRegistry::class)->type('longText', 'markdown')->name('*_url', 'url');
 *
 * Values are the field types of `fields:` in the draft (see FieldTypes::names()); anything else is an error that
 * names the rule it came from.
 */
final class FieldTypeRegistry
{
    /** Category => Nova field type: what a column becomes when nothing more specific says otherwise. */
    public const DEFAULTS = [
        'integer' => 'number',
        'decimal' => 'number',
        'string' => 'text',
        'text' => 'textarea',
        'json' => 'code',
        'boolean' => 'boolean',
        'date' => 'date',
        'datetime' => 'datetime',
        'enum' => 'select',
    ];

    /** Used when the config has no `name_hints` (a config published before the option existed). */
    public const DEFAULT_NAME_HINTS = [
        'email' => ['email', '*_email'],
        'password' => ['password'],
        'url' => ['url', '*_url', 'website'],
    ];

    /** Name rules look at columns that hold words, not numbers or dates. */
    private const NAME_CATEGORIES = ['string', 'text'];

    /** @var array<string, string> */
    private array $types = [];

    /** @var array<int, array{0: string, 1: string}> pattern, Nova type, in registration order */
    private array $names = [];

    /** A Blueprint type (`longText`) or category (`text`) becomes this Nova field type. */
    public function type(string $key, string $novaType): static
    {
        $this->types[$key] = $this->checked($novaType, "type rule [{$key}]");

        return $this;
    }

    /**
     * Columns whose name matches (fnmatch patterns) become this Nova field type.
     *
     * @param  string|array<int, string>  $patterns
     */
    public function name(string|array $patterns, string $novaType): static
    {
        $novaType = $this->checked($novaType, 'name rule [' . implode(', ', (array) $patterns) . ']');

        foreach ((array) $patterns as $pattern) {
            $this->names[] = [(string) $pattern, $novaType];
        }

        return $this;
    }

    /**
     * The Nova field type for a column, or null when nothing applies (a column type this package has no field for).
     * The primary key and housekeeping columns are not this registry's business and return null.
     */
    public function resolve(ColumnInfo $column): ?string
    {
        $category = $column->category();

        if (in_array($category, ['primary', 'ignored'], true)) {
            return null;
        }

        // A foreign key without a Nova resource to point at is shown as a plain number; keep it that way.
        if ($column->foreign) {
            return self::DEFAULTS[$category] ?? null;
        }

        return $this->byName($column, $category)
            ?? $this->byType($column, $category)
            ?? (self::DEFAULTS[$category] ?? null);
    }

    private function byName(ColumnInfo $column, string $category): ?string
    {
        if (! in_array($category, self::NAME_CATEGORIES, true)) {
            return null;
        }

        foreach (array_reverse($this->names) as [$pattern, $type]) {
            if (fnmatch($pattern, $column->name)) {
                return $type;
            }
        }

        foreach ((array) config('blueprint_nova_resources.name_hints', self::DEFAULT_NAME_HINTS) as $type => $patterns) {
            foreach ((array) $patterns as $pattern) {
                if (fnmatch((string) $pattern, $column->name)) {
                    return $this->checked((string) $type, "name_hints [{$type}]");
                }
            }
        }

        return null;
    }

    private function byType(ColumnInfo $column, string $category): ?string
    {
        $configured = (array) config('blueprint_nova_resources.field_types', []);

        // The exact Blueprint type is more specific than its category, wherever each was registered.
        foreach ([$column->type, $category] as $key) {
            if (isset($this->types[$key])) {
                return $this->types[$key];
            }

            if (isset($configured[$key])) {
                return $this->checked((string) $configured[$key], "field_types [{$key}]");
            }
        }

        return null;
    }

    private function checked(string $type, string $where): string
    {
        $type = strtolower(trim($type));

        if (! isset(FieldTypes::CLASSES[$type])) {
            throw new InvalidArgumentException(sprintf(
                'blueprint_nova_resources: %s maps to [%s], which is not a Nova field type this package knows. Valid types: %s.',
                $where,
                $type,
                implode(', ', FieldTypes::names()),
            ));
        }

        return $type;
    }
}
