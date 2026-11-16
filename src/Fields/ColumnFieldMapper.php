<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintNovaResources\Fields;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The built-in mapper: column definition (type, nullability, length, enum values, uniqueness, name) to Nova field.
 * Draft overrides (`fields:`) always win over what is derived here.
 */
final class ColumnFieldMapper implements FieldMapper
{
    private const DEFAULT_HINTS = [
        'email' => ['email', '*_email'],
        'password' => ['password'],
        'url' => ['url', '*_url', 'website'],
    ];

    public function map(FieldContext $context): ?array
    {
        $column = $context->column;
        $type = $context->options->as ?? $this->defaultType($column);

        return match (true) {
            $type === null => null,
            $type === 'ignored' => [],
            $type === 'id' => [$this->id($context)],
            $type === 'badge' => $this->badge($context),
            $type === 'password' => [$this->password($context)],
            default => [$this->standard($type, $context)],
        };
    }

    private function defaultType(ColumnInfo $column): ?string
    {
        return match ($column->category()) {
            'primary' => 'id',
            'ignored' => 'ignored',
            'integer', 'decimal' => 'number',
            'string' => $this->hint($column->name) ?? 'text',
            'text' => 'textarea',
            'json' => 'code',
            'boolean' => 'boolean',
            'date' => 'date',
            'datetime' => 'datetime',
            'enum' => 'select',
            default => null,
        };
    }

    /** Column names that deserve a more specific field than plain text (`email`, `password`, `*_url`, ...). */
    private function hint(string $name): ?string
    {
        $hints = (array) config('blueprint_nova_resources.name_hints', self::DEFAULT_HINTS);

        foreach ($hints as $type => $patterns) {
            foreach ((array) $patterns as $pattern) {
                if (fnmatch((string) $pattern, $name)) {
                    return (string) $type;
                }
            }
        }

        return null;
    }

    private function id(FieldContext $context): FieldDefinition
    {
        $field = $this->sortable(new FieldDefinition(FieldTypes::ID), 'number', $context);

        return $context->options->applyVisibility($field);
    }

    private function standard(string $type, FieldContext $context): FieldDefinition
    {
        $column = $context->column;
        $options = $context->options;

        $field = FieldDefinition::make(FieldTypes::CLASSES[$type], $this->label($context), $column->name);

        if ($type === 'number' && $column->category() === 'decimal') {
            $field = $field->with('step', PhpLiteral::string($this->step($column)));
        }

        if ($type === 'select') {
            if ($column->enum === []) {
                throw new InvalidArgumentException(
                    "Nova resource [{$context->resource}] field [{$column->name}] uses `select`, but the column is not an enum.",
                );
            }

            $field = $field->with('options', PhpLiteral::map($this->labels($column->enum)))->with('displayUsingLabels');
        }

        if ($type === 'code' && $column->category() === 'json') {
            $field = $field->with('json');
        }

        $field = $this->sortable($field, $type, $context);

        if ($column->nullable) {
            $field = $field->with('nullable');
        }

        $default = $this->defaultSource($type, $column);
        if ($default !== null) {
            $field = $field->with('default', $default);
        }

        $rules = $options->rules !== null
            ? array_map(PhpLiteral::string(...), $options->rules)
            : $this->rules($type, $column);

        if ($rules !== []) {
            $field = $field->with('rules', ...$rules);
        }

        if ($column->unique && ! $column->primary) {
            $field = $field
                ->with('creationRules', PhpLiteral::string("unique:{$context->table},{$column->name}"))
                ->with('updateRules', PhpLiteral::string("unique:{$context->table},{$column->name},{{resourceId}}"));
        }

        return $options->applyVisibility($field);
    }

    private function password(FieldContext $context): FieldDefinition
    {
        $extra = array_map(
            PhpLiteral::string(...),
            (array) config('blueprint_nova_resources.password_rules', ['string', 'min:8']),
        );

        $field = FieldDefinition::make(FieldTypes::CLASSES['password'], $this->label($context), $context->column->name)
            ->with('onlyOnForms')
            ->with('creationRules', PhpLiteral::string('required'), ...$extra)
            ->with('updateRules', PhpLiteral::string('nullable'), ...$extra);

        return $context->options->applyVisibility($field);
    }

    /**
     * Enum as a coloured badge on the index/detail pages. Badge fields cannot be filled, so a select takes over
     * on the forms.
     *
     * @return array<int, FieldDefinition>
     */
    private function badge(FieldContext $context): array
    {
        $column = $context->column;
        $map = $context->options->map ?? ($column->enum !== [] ? array_fill_keys($column->enum, 'info') : null);

        if ($map === null) {
            throw new InvalidArgumentException(
                "Nova resource [{$context->resource}] field [{$column->name}] uses `badge`, but the column is not an enum. "
                . 'Add `map: { value: success|info|warning|danger }` to say how its values should look.',
            );
        }

        $badge = FieldDefinition::make(FieldTypes::CLASSES['badge'], $this->label($context), $column->name)
            ->with('map', PhpLiteral::map($map));
        $badge = $context->options->applyVisibility($this->sortable($badge, 'badge', $context))->with('exceptOnForms');

        if ($column->enum === []) {
            return [$badge];
        }

        return [$badge, $this->standard('select', $context)->with('onlyOnForms')];
    }

    private function label(FieldContext $context): string
    {
        return $context->options->label ?? Str::headline($context->column->name);
    }

    private function sortable(FieldDefinition $field, string $type, FieldContext $context): FieldDefinition
    {
        $sortable = $context->options->sortable
            ?? ((bool) config('blueprint_nova_resources.sortable', true) && in_array($type, [...FieldTypes::SORTABLE, 'id'], true));

        return $sortable ? $field->with('sortable') : $field;
    }

    /**
     * Blueprint's `default:` as the value a Nova create form starts with, as PHP source. Nova does not read
     * database defaults, so without this a Boolean saves as false and a text field starts empty.
     * Null when there is no default or it is not a plain literal (expressions, dates, `null`).
     */
    private function defaultSource(string $type, ColumnInfo $column): ?string
    {
        $raw = $column->default;

        if ($raw === null || is_array($raw) || ! in_array($type, ['boolean', 'number', 'currency', 'text', 'textarea', 'email', 'url', 'select', 'color'], true)) {
            return null;
        }

        if (is_string($raw)) {
            $raw = trim($raw);
            if (strlen($raw) >= 2 && $raw[0] === $raw[-1] && ($raw[0] === '"' || $raw[0] === "'")) {
                $raw = substr($raw, 1, -1);
            } elseif (strtolower($raw) === 'null') {
                return null;
            }
        }

        if ($type === 'boolean') {
            return match (true) {
                $raw === true, $raw === 1, in_array(is_string($raw) ? strtolower($raw) : $raw, ['true', '1'], true) => 'true',
                $raw === false, $raw === 0, in_array(is_string($raw) ? strtolower($raw) : $raw, ['false', '0'], true) => 'false',
                default => null,
            };
        }

        if (is_bool($raw)) {
            return null;
        }

        if ($type === 'number' || $type === 'currency') {
            // Plain decimal literals only: "007" would be octal and "1e3" a float once written as PHP source.
            return preg_match('~^-?(?:0|[1-9]\d*)(?:\.\d+)?$~', (string) $raw) === 1 ? (string) $raw : null;
        }

        $raw = (string) $raw;

        if ($type === 'select' && ! in_array($raw, $column->enum, true)) {
            return null;
        }

        return PhpLiteral::string($raw);
    }

    /** @return array<int, string>  Validation rules as PHP source */
    private function rules(string $type, ColumnInfo $column): array
    {
        $rules = [];

        if (! $column->nullable && $column->default === null && ! in_array($type, ['boolean', 'hidden'], true)) {
            $rules[] = 'required';
        }

        $isText = in_array($column->type, ['string', 'char'], true);
        $max = 'max:' . ($column->length ?? 255);

        switch ($type) {
            case 'text':
                if ($isText) {
                    $rules[] = $max;
                }
                break;
            case 'email':
            case 'url':
                $rules[] = $type;
                if ($isText) {
                    $rules[] = $max;
                }
                break;
            case 'number':
                $integer = $column->category() === 'integer';
                $rules[] = $integer ? 'integer' : 'numeric';
                if ($integer && $column->unsigned) {
                    $rules[] = 'min:0';
                }
                break;
            case 'currency':
                $rules[] = 'numeric';
                break;
            case 'select':
                if ($column->enum !== []) {
                    return [...array_map(PhpLiteral::string(...), $rules), $this->inRule($column->enum)];
                }
                break;
        }

        return array_map(PhpLiteral::string(...), $rules);
    }

    /** @param  array<int, string>  $values */
    private function inRule(array $values): string
    {
        foreach ($values as $value) {
            if (str_contains($value, ',') || str_contains($value, '|')) {
                return '{{ use:Illuminate\\Validation\\Rule }}::in(' . PhpLiteral::list($values) . ')';
            }
        }

        return PhpLiteral::string('in:' . implode(',', $values));
    }

    /**
     * @param  array<int, string>  $values
     * @return array<string, string>
     */
    private function labels(array $values): array
    {
        $labels = [];
        foreach ($values as $value) {
            $labels[$value] = Str::headline($value);
        }

        return $labels;
    }

    private function step(ColumnInfo $column): string
    {
        return match (true) {
            $column->scale === null => 'any',
            $column->scale <= 0 => '1',
            default => '0.' . str_repeat('0', $column->scale - 1) . '1',
        };
    }
}
