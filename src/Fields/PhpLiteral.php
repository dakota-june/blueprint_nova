<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintNovaResources\Fields;

/** Renders PHP values as source code. Only what the generator needs: strings, bools, numbers and lists or maps of them. */
final class PhpLiteral
{
    public static function string(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    public static function bool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    public static function int(int $value): string
    {
        return (string) $value;
    }

    /** Always written with a decimal point (`1.0`, `0.5`) so the source reads as a float. */
    public static function float(float $value): string
    {
        return var_export($value, true);
    }

    /** @param  array<int, string>  $items */
    public static function list(array $items): string
    {
        return '[' . implode(', ', array_map(self::string(...), array_values($items))) . ']';
    }

    /** @param  array<int, int>  $items */
    public static function intList(array $items): string
    {
        return '[' . implode(', ', array_map(self::int(...), array_values($items))) . ']';
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
