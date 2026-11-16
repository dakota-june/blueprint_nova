<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintNovaResources\Support;

use Dakotajune\BlueprintNovaResources\Fields\PhpLiteral;
use InvalidArgumentException;

/**
 * The one table of resource-level options a `nova:` entry accepts besides model/group/title/search/fields/relations.
 *
 * Each option maps a draft key to a Nova resource member (a static property, or a static method that returns a
 * literal). The lexer, the generator and the docs all read from DEFINITIONS, so adding an option is one entry here.
 *
 * Rule: a member is written only when the draft states a value that differs from Nova's own default. Anything
 * omitted, or equal to the default, is not written, and a generated member whose option disappears is removed by
 * ResourceClassEditor on the next build.
 *
 *   navigation: false          # $displayInNavigation
 *   label: Bills               # label()
 *   singular: Bill             # singularLabel()   (Nova derives it from label() when omitted)
 *   globalSearch:              # or `globalSearch: false`
 *     enabled: true
 *     results: 10
 *     link: edit
 *
 * Defaults and member names follow Nova 5's Laravel\Nova\Resource.
 */
final class ResourceOptions
{
    /** Top-level keys of a `nova:` entry that belong to this table. */
    public const KEYS = [
        'navigation',
        'label',
        'singular',
        'searchable',
        'globalSearch',
        'trafficCop',
        'showColumnBorders',
        'tableStyle',
        'clickAction',
        'debounce',
        'perPage',
        'perPageViaRelationship',
        'relatableSearchResults',
        'scoutSearchResults',
    ];

    /** Keys allowed inside `globalSearch: { ... }`. */
    public const GLOBAL_SEARCH_KEYS = ['enabled', 'results', 'link'];

    /**
     * Keyed by dotted path. `default` null means "Nova has no useful default": emit whenever the draft sets it.
     *
     * @var array<string, array{kind:string, member:string, type:string, default:mixed, doc:string, values?:array<int,string>, min?:int}>
     */
    private const DEFINITIONS = [
        'navigation' => [
            'kind' => 'property', 'member' => 'displayInNavigation', 'type' => 'bool', 'default' => true,
            'doc' => 'Indicates if the resource should be displayed in the sidebar.',
        ],
        'label' => [
            'kind' => 'method', 'member' => 'label', 'type' => 'string', 'default' => null,
            'doc' => 'Get the displayable label of the resource.',
        ],
        'singular' => [
            'kind' => 'method', 'member' => 'singularLabel', 'type' => 'string', 'default' => null,
            'doc' => 'Get the displayable singular label of the resource.',
        ],
        'searchable' => [
            'kind' => 'property', 'member' => 'searchable', 'type' => 'bool', 'default' => true,
            'doc' => 'Indicates if the resource should be searchable on the index view.',
        ],
        'globalSearch.enabled' => [
            'kind' => 'property', 'member' => 'globallySearchable', 'type' => 'bool', 'default' => true,
            'doc' => 'Indicates if the resource should be globally searchable.',
        ],
        'globalSearch.results' => [
            'kind' => 'property', 'member' => 'globalSearchResults', 'type' => 'int', 'default' => 5, 'min' => 1,
            'doc' => 'The number of results to display in the global search.',
        ],
        'globalSearch.link' => [
            'kind' => 'property', 'member' => 'globalSearchLink', 'type' => 'string', 'default' => 'detail',
            'doc' => 'Where the global search should link to.',
        ],
        'trafficCop' => [
            'kind' => 'property', 'member' => 'trafficCop', 'type' => 'bool', 'default' => true,
            'doc' => 'Indicates whether Nova should check for modifications between viewing and updating a resource.',
        ],
        'showColumnBorders' => [
            'kind' => 'property', 'member' => 'showColumnBorders', 'type' => 'bool', 'default' => false,
            'doc' => 'Whether to show borders for each column on the X-axis.',
        ],
        'tableStyle' => [
            'kind' => 'property', 'member' => 'tableStyle', 'type' => 'enum', 'default' => 'default',
            'values' => ['default', 'tight'],
            'doc' => 'The visual style used for the table.',
        ],
        'clickAction' => [
            'kind' => 'property', 'member' => 'clickAction', 'type' => 'enum', 'default' => 'detail',
            'values' => ['detail', 'edit', 'select', 'preview', 'ignore'],
            'doc' => 'The click action to use when clicking on the resource in the table.',
        ],
        'debounce' => [
            'kind' => 'property', 'member' => 'debounce', 'type' => 'float', 'default' => 0.5,
            'doc' => 'The debounce amount to use when searching this resource.',
        ],
        'perPage' => [
            'kind' => 'property', 'member' => 'perPageOptions', 'type' => 'int-list', 'default' => [25, 50, 100],
            'doc' => 'The pagination per-page options used by the resource index.',
        ],
        'perPageViaRelationship' => [
            'kind' => 'property', 'member' => 'perPageViaRelationshipOptions', 'type' => 'int-list', 'default' => null,
            'doc' => 'The number of resources to show per page via relationships.',
        ],
        'relatableSearchResults' => [
            'kind' => 'property', 'member' => 'relatableSearchResults', 'type' => 'int', 'default' => null, 'min' => 1,
            'doc' => 'The number of results to display when searching relatable resources without Scout.',
        ],
        'scoutSearchResults' => [
            'kind' => 'property', 'member' => 'scoutSearchResults', 'type' => 'int', 'default' => 200, 'min' => 1,
            'doc' => 'The number of results to display when searching the resource using Scout.',
        ],
    ];

    /**
     * Reads the option keys out of one `nova:` entry and validates them.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>  dotted path => normalised value; only keys the draft actually set
     */
    public static function parse(string $resource, array $definition): array
    {
        $settings = [];

        foreach (self::KEYS as $key) {
            $value = $definition[$key] ?? null;

            if ($value === null) {
                continue;
            }

            if ($key === 'globalSearch') {
                $settings = [...$settings, ...self::globalSearch($resource, $value)];

                continue;
            }

            $settings[$key] = self::normalise($resource, $key, $value);
        }

        return $settings;
    }

    /**
     * The settings worth writing: those that differ from Nova's default, in table order so output is stable
     * whatever order the draft listed them in.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function differing(array $settings): array
    {
        $differing = [];

        foreach (self::DEFINITIONS as $path => $option) {
            if (! array_key_exists($path, $settings)) {
                continue;
            }

            if ($option['default'] !== null && $settings[$path] == $option['default']) {
                continue;
            }

            $differing[$path] = $settings[$path];
        }

        return $differing;
    }

    /** @return array{kind:string, member:string, type:string, default:mixed, doc:string, values?:array<int,string>, min?:int} */
    public static function definition(string $path): array
    {
        return self::DEFINITIONS[$path];
    }

    /** The `@var` type for a property option's docblock. */
    public static function varType(string $path): string
    {
        $option = self::DEFINITIONS[$path];

        $type = match ($option['type']) {
            'bool' => 'bool',
            'int' => 'int',
            'float' => 'float',
            'int-list' => 'array<int, int>',
            default => 'string',
        };

        return $option['default'] === null ? $type . '|null' : $type;
    }

    /** A normalised value as PHP source. */
    public static function literal(string $path, mixed $value): string
    {
        return match (self::DEFINITIONS[$path]['type']) {
            'bool' => PhpLiteral::bool((bool) $value),
            'int' => PhpLiteral::int((int) $value),
            'float' => PhpLiteral::float((float) $value),
            'int-list' => PhpLiteral::intList((array) $value),
            default => PhpLiteral::string((string) $value),
        };
    }

    /** @return array<string, mixed> */
    private static function globalSearch(string $resource, mixed $value): array
    {
        if ($value === true) {
            return [];
        }

        if ($value === false) {
            return ['globalSearch.enabled' => false];
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException(
                "Nova resource [{$resource}]: `globalSearch` must be true, false or a map of " . implode(', ', self::GLOBAL_SEARCH_KEYS) . '.',
            );
        }

        $settings = [];

        foreach ($value as $key => $setting) {
            if (! in_array($key, self::GLOBAL_SEARCH_KEYS, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Nova resource [%s]: `globalSearch` has unknown option [%s]. Valid options: %s.',
                    $resource,
                    (string) $key,
                    implode(', ', self::GLOBAL_SEARCH_KEYS),
                ));
            }

            if ($setting !== null) {
                $settings["globalSearch.{$key}"] = self::normalise($resource, "globalSearch.{$key}", $setting);
            }
        }

        return $settings;
    }

    private static function normalise(string $resource, string $path, mixed $value): mixed
    {
        $option = self::DEFINITIONS[$path];
        $fail = static fn (string $expected): InvalidArgumentException => new InvalidArgumentException(
            "Nova resource [{$resource}]: `{$path}` must be {$expected}.",
        );

        return match ($option['type']) {
            'bool' => is_bool($value) ? $value : throw $fail('true or false'),
            'int' => is_int($value) && $value >= ($option['min'] ?? 1)
                ? $value
                : throw $fail('a whole number, ' . ($option['min'] ?? 1) . ' or more'),
            'float' => (is_int($value) || is_float($value)) && $value >= 0
                ? (float) $value
                : throw $fail('a number, 0 or more'),
            'string' => is_string($value) && trim($value) !== '' ? trim($value) : throw $fail('text'),
            'enum' => is_string($value) && in_array(strtolower(trim($value)), $option['values'] ?? [], true)
                ? strtolower(trim($value))
                : throw $fail('one of ' . implode(', ', $option['values'] ?? [])),
            'int-list' => self::intList($value, $fail),
            default => throw $fail('a supported value'),
        };
    }

    /**
     * One whole number or a list of them, always returned as a list.
     *
     * @return array<int, int>
     */
    private static function intList(mixed $value, \Closure $fail): array
    {
        $items = is_array($value) ? array_values($value) : [$value];

        if ($items === []) {
            throw $fail('a number or a list of numbers, 1 or more');
        }

        foreach ($items as $item) {
            if (! is_int($item) || $item < 1) {
                throw $fail('a number or a list of numbers, 1 or more');
            }
        }

        return $items;
    }
}
