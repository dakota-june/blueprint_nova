<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintNovaResources\Fields;

use Dakotajune\BlueprintNovaResources\Support\ResourceClassEditor;

/**
 * One Nova field as source code: `Text::make('Number', 'number')->sortable()->rules('required')`.
 *
 * Immutable. Arguments and call arguments are PHP *source* strings, so they can hold class references. Class
 * names are written as `{{ use:Fqcn }}` placeholders that ResourceClassEditor turns into imports.
 */
final class FieldDefinition
{
    /**
     * @param  class-string  $class  Fully qualified Nova field class
     * @param  array<int, string>  $arguments  Arguments of ::make(), as PHP source
     * @param  array<int, array{0:string, 1:array<int, string>}>  $calls  Chained calls: [method, [argument source, ...]]
     */
    public function __construct(
        public readonly string $class,
        public readonly array $arguments = [],
        public readonly array $calls = [],
    ) {}

    /** Field whose label and attribute are written explicitly, so nothing depends on how Nova derives the attribute. */
    public static function make(string $class, string $label, ?string $attribute = null): self
    {
        $arguments = [PhpLiteral::string($label)];

        if ($attribute !== null) {
            $arguments[] = PhpLiteral::string($attribute);
        }

        return new self($class, $arguments);
    }

    public function with(string $method, string ...$arguments): self
    {
        return new self($this->class, $this->arguments, [...$this->calls, [$method, array_values($arguments)]]);
    }

    public function has(string $method): bool
    {
        foreach ($this->calls as [$name]) {
            if ($name === $method) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  int  $indent  Columns the first line will be indented by (used to decide whether to wrap)
     * @param  int  $width  Wrap the call chain onto separate lines when a line would be longer than this
     */
    public function render(int $indent = 12, int $width = 100): string
    {
        $head = '{{ use:' . ltrim($this->class, '\\') . ' }}::make(' . implode(', ', $this->arguments) . ')';
        $chain = array_map(
            static fn (array $call): string => '->' . $call[0] . '(' . implode(', ', $call[1]) . ')',
            $this->calls,
        );

        $oneLine = $head . implode('', $chain);

        if ($chain === [] || $indent + strlen($this->visible($oneLine)) + 1 <= $width) {
            return $oneLine;
        }

        return $head . "\n" . implode("\n", array_map(static fn (string $c): string => '    ' . $c, $chain));
    }

    /** The line as it will read once placeholders are replaced by short class names. */
    private function visible(string $source): string
    {
        return (string) preg_replace_callback(
            ResourceClassEditor::USE_PATTERN,
            static fn (array $m): string => substr(strrchr('\\' . $m[1], '\\'), 1),
            $source,
        );
    }
}
