<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintNovaResources\Support;

/** Renders the members the generator owns, each carrying the `@generated blueprint_nova_resources` ownership tag. */
final class ResourceMemberBuilder
{
    public static function property(string $name, string $description, string $value, string $type): string
    {
        return implode("\n", [
            '    /**',
            "     * {$description}",
            '     *',
            '     * ' . ResourceClassEditor::MARKER . ' Rewritten on every build. Remove this tag to take ownership of the property.',
            '     *',
            "     * @var {$type}",
            '     */',
            "    public static \${$name} = {$value};",
        ]);
    }

    /**
     * A static method whose whole body is `return <literal>;`, e.g. label() and singularLabel().
     *
     * @param  string  $value  The returned value, as PHP source
     */
    public static function staticMethod(string $name, string $description, string $returnType, string $value): string
    {
        return implode("\n", [
            '    /**',
            "     * {$description}",
            '     *',
            '     * ' . ResourceClassEditor::MARKER . ' Rewritten on every build. Remove this tag to take ownership of the method.',
            '     *',
            "     * @return {$returnType}",
            '     */',
            "    public static function {$name}(): {$returnType}",
            '    {',
            "        return {$value};",
            '    }',
        ]);
    }

    /**
     * @param  array<int, string>  $fields  Rendered fields (FieldDefinition::render()), without trailing commas
     */
    public static function fields(array $fields): string
    {
        $lines = [
            '    /**',
            '     * Get the fields derived from the draft: columns, relationships and timestamps.',
            '     *',
            '     * ' . ResourceClassEditor::MARKER . ' Rewritten on every build. Remove this tag to take ownership of the method.',
            '     * Put your own fields in fields(), which is never touched.',
            '     *',
            '     * @return array<int, \Laravel\Nova\Fields\Field>',
            '     */',
            '    public function blueprintFields({{ use:Laravel\Nova\Http\Requests\NovaRequest }} $request): array',
            '    {',
        ];

        if ($fields === []) {
            $lines[] = '        return [];';
        } else {
            $lines[] = '        return [';
            $previousWrapped = false;

            foreach ($fields as $i => $field) {
                $wrapped = str_contains($field, "\n");

                if ($i > 0 && ($wrapped || $previousWrapped)) {
                    $lines[] = '';
                }

                foreach (explode("\n", $field . ',') as $line) {
                    $lines[] = '            ' . $line;
                }

                $previousWrapped = $wrapped;
            }

            $lines[] = '        ];';
        }

        $lines[] = '    }';

        return implode("\n", $lines);
    }
}
