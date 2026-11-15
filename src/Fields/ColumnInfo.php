<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintNovaResources\Fields;

use Blueprint\Models\Column;

/**
 * What the field mapper needs to know about one column, independent of Blueprint's own classes
 * so mappers (including third-party ones) stay simple to write and to test.
 */
final class ColumnInfo
{
    private const INTEGERS = [
        'integer', 'bigInteger', 'mediumInteger', 'smallInteger', 'tinyInteger',
        'unsignedInteger', 'unsignedBigInteger', 'unsignedMediumInteger', 'unsignedSmallInteger', 'unsignedTinyInteger',
        'increments', 'bigIncrements', 'mediumIncrements', 'smallIncrements', 'tinyIncrements', 'id', 'year',
    ];

    private const DECIMALS = ['decimal', 'unsignedDecimal', 'float', 'double'];

    private const STRINGS = ['string', 'char', 'uuid', 'ulid', 'ipAddress', 'macAddress', 'time', 'timeTz', 'set'];

    private const TEXTS = ['text', 'mediumText', 'longText'];

    private const DATETIMES = ['dateTime', 'dateTimeTz', 'timestamp', 'timestampTz'];

    private const IGNORED = ['rememberToken', 'timestamps', 'timestampsTz', 'nullableTimestamps', 'softDeletes', 'softDeletesTz'];

    /** @param  array<int, string>  $enum */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $nullable = false,
        public readonly bool $unique = false,
        public readonly ?int $length = null,
        public readonly ?int $scale = null,
        public readonly array $enum = [],
        public readonly mixed $default = null,
        public readonly bool $unsigned = false,
        public readonly bool $primary = false,
        public readonly bool $foreign = false,
    ) {}

    public static function fromBlueprint(Column $column, bool $primary): self
    {
        $type = $column->dataType();
        $attributes = array_values($column->attributes());
        $isUuidKey = in_array($type, ['uuid', 'ulid'], true) && str_ends_with($column->name(), '_id');

        return new self(
            name: $column->name(),
            type: $type,
            nullable: $column->isNullable(),
            unique: in_array('unique', $column->modifiers(), true),
            length: in_array($type, ['string', 'char'], true) && isset($attributes[0]) && ctype_digit((string) $attributes[0])
                ? (int) $attributes[0] : null,
            scale: in_array($type, self::DECIMALS, true) && isset($attributes[1]) && ctype_digit((string) $attributes[1])
                ? (int) $attributes[1] : null,
            enum: $type === 'enum' ? array_map('strval', $attributes) : [],
            default: $column->defaultValue(),
            unsigned: $column->isUnsigned() || $type === 'id' || str_starts_with($type, 'unsigned'),
            primary: $primary,
            foreign: ! $primary && ($type === 'id' || $isUuidKey || (bool) $column->isForeignKey()),
        );
    }

    /**
     * primary | integer | decimal | string | text | json | boolean | date | datetime | enum | ignored | unsupported
     */
    public function category(): string
    {
        return match (true) {
            $this->primary => 'primary',
            $this->name === 'remember_token', in_array($this->type, self::IGNORED, true) => 'ignored',
            in_array($this->type, self::INTEGERS, true) => 'integer',
            in_array($this->type, self::DECIMALS, true) => 'decimal',
            in_array($this->type, self::STRINGS, true) => 'string',
            in_array($this->type, self::TEXTS, true) => 'text',
            in_array($this->type, ['json', 'jsonb'], true) => 'json',
            $this->type === 'boolean' => 'boolean',
            $this->type === 'date' => 'date',
            in_array($this->type, self::DATETIMES, true) => 'datetime',
            $this->type === 'enum' => 'enum',
            default => 'unsupported',
        };
    }
}
