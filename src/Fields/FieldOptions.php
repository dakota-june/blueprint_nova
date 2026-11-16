<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintNovaResources\Fields;

/**
 * What the draft says about one field: `fields: status: badge` or the long form
 * `fields: status: { as: badge, label: State, help: Pick one, hide: [index], readonly: true, sortable: false, rules: [...], map: {...} }`.
 */
final class FieldOptions
{
    /** The badge types Nova defines (Laravel\Nova\Badge constants). */
    public const BADGE_TYPES = ['info', 'success', 'warning', 'danger'];

    public const HIDE = ['index' => 'hideFromIndex', 'detail' => 'hideFromDetail', 'create' => 'hideWhenCreating', 'update' => 'hideWhenUpdating'];

    /**
     * @param  array<int, string>  $hide  index | detail | create | update
     * @param  array<int, string>|null  $rules  Replaces the rules derived from the column
     * @param  array<string, string>|null  $map  value => badge type, for `as: badge`
     * @param  ?string  $help  Help text shown with the field (Nova's `->help()`)
     */
    public function __construct(
        public readonly bool $omit = false,
        public readonly ?string $as = null,
        public readonly ?string $label = null,
        public readonly array $hide = [],
        public readonly ?bool $readonly = null,
        public readonly ?bool $sortable = null,
        public readonly ?array $rules = null,
        public readonly ?array $map = null,
        public readonly ?string $help = null,
    ) {}

    public static function none(): self
    {
        return new self();
    }

    /**
     * These options, with whatever they leave unsaid taken from $fallback. What is set here always wins; a column
     * left out here stays out; $fallback's own omit is ignored.
     */
    public function orElse(self $fallback): self
    {
        if ($this->omit) {
            return $this;
        }

        return new self(
            as: $this->as ?? $fallback->as,
            label: $this->label ?? $fallback->label,
            hide: $this->hide !== [] ? $this->hide : $fallback->hide,
            readonly: $this->readonly ?? $fallback->readonly,
            sortable: $this->sortable ?? $fallback->sortable,
            rules: $this->rules ?? $fallback->rules,
            map: $this->map ?? $fallback->map,
            help: $this->help ?? $fallback->help,
        );
    }

    /** Adds the visibility, read-only and help calls the draft asked for. */
    public function applyVisibility(FieldDefinition $field): FieldDefinition
    {
        foreach ($this->hide as $where) {
            $field = $field->with(self::HIDE[$where]);
        }

        if ($this->readonly === true) {
            $field = $field->with('readonly');
        }

        return $this->help !== null ? $field->with('help', PhpLiteral::string($this->help)) : $field;
    }
}
