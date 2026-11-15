<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintNovaResources\Fields;

/** Renders PHP values as source code. Only what the generator needs: strings, lists and maps of them. */
final class PhpLiteral
{
    public static function string(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    /** @param  array<int, string>  $items */
    public static function list(array $items): string
    {
        return '[' . implode(', ', array_map(self::string(...), array_values($items))) . ']';
    }

    /** @param  array<string, string>  $items */
    public static function map(array $items): string
    {
        $parts = [];
        foreach ($items as $key => $value) {
            $parts[] = self::string((string) $key) . ' => ' . self::string($value);
        }

        return '[' . implode(', ', $parts) . ']';
    }
}
