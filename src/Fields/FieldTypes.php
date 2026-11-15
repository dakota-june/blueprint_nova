<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintNovaResources\Fields;

/**
 * The field types a draft may ask for with `fields: column: <type>` (or `as: <type>`).
 * Every class below is documented in the Laravel Nova API reference this package was written against.
 */
final class FieldTypes
{
    public const CLASSES = [
        'text' => 'Laravel\\Nova\\Fields\\Text',
        'textarea' => 'Laravel\\Nova\\Fields\\Textarea',
        'markdown' => 'Laravel\\Nova\\Fields\\Markdown',
        'trix' => 'Laravel\\Nova\\Fields\\Trix',
        'code' => 'Laravel\\Nova\\Fields\\Code',
        'number' => 'Laravel\\Nova\\Fields\\Number',
        'currency' => 'Laravel\\Nova\\Fields\\Currency',
        'boolean' => 'Laravel\\Nova\\Fields\\Boolean',
        'date' => 'Laravel\\Nova\\Fields\\Date',
        'datetime' => 'Laravel\\Nova\\Fields\\DateTime',
        'select' => 'Laravel\\Nova\\Fields\\Select',
        'badge' => 'Laravel\\Nova\\Fields\\Badge',
        'email' => 'Laravel\\Nova\\Fields\\Email',
        'url' => 'Laravel\\Nova\\Fields\\URL',
        'password' => 'Laravel\\Nova\\Fields\\Password',
        'color' => 'Laravel\\Nova\\Fields\\Color',
        'country' => 'Laravel\\Nova\\Fields\\Country',
        'timezone' => 'Laravel\\Nova\\Fields\\Timezone',
        'hidden' => 'Laravel\\Nova\\Fields\\Hidden',
    ];

    public const ID = 'Laravel\\Nova\\Fields\\ID';

    public const BELONGS_TO = 'Laravel\\Nova\\Fields\\BelongsTo';

    public const HAS_ONE = 'Laravel\\Nova\\Fields\\HasOne';

    public const HAS_MANY = 'Laravel\\Nova\\Fields\\HasMany';

    public const BELONGS_TO_MANY = 'Laravel\\Nova\\Fields\\BelongsToMany';

    public const DATE_TIME = 'Laravel\\Nova\\Fields\\DateTime';

    /** Types that make sense to sort the index by. */
    public const SORTABLE = ['text', 'number', 'currency', 'boolean', 'date', 'datetime', 'select', 'badge', 'email', 'url', 'color', 'country', 'timezone'];

    /** @return array<int, string> */
    public static function names(): array
    {
        return array_keys(self::CLASSES);
    }
}
