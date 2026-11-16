<?php

declare(strict_types=1);

use Dakotajune\BlueprintNovaResources\Support\EditResult;
use Dakotajune\BlueprintNovaResources\Support\ResourceClassEditor;
use Dakotajune\BlueprintNovaResources\Support\ResourceMemberBuilder;

function resourceUsing(string $uses): string
{
    return "<?php\n\nnamespace App\\Nova;\n\n{$uses}\n\nclass Invoice extends Resource\n{\n    public function fields(\$request)\n    {\n        return [];\n    }\n}\n";
}

/** Asks the editor for a generated blueprintFields() that needs Laravel\Nova\Fields\Text. */
function addTextField(string $source): EditResult
{
    return (new ResourceClassEditor())->update($source, 'Invoice', [], [
        'blueprintFields' => ResourceMemberBuilder::fields(["{{ use:Laravel\\Nova\\Fields\\Text }}::make('A', 'a')"]),
    ]);
}

it('does not import a class that a group import already provides', function (string $uses) {
    $result = addTextField(resourceUsing($uses));

    expect($result->source)
        ->not->toContain('use Laravel\Nova\Fields\Text;')
        ->not->toContain('\Laravel\Nova\Fields\Text::make')
        ->toContain("Text::make('A', 'a')");
    expect(fn () => token_get_all($result->source, TOKEN_PARSE))->not->toThrow(ParseError::class);
})->with([
    'plain group' => ['use Laravel\Nova\Fields\{Text, Number};'],
    'text last' => ['use Laravel\Nova\Fields\{Number, Text};'],
    'multi-line with trailing comma' => ["use Laravel\\Nova\\Fields\\{\n    Number,\n    Text,\n};"],
    'nested path in the group' => ['use Laravel\Nova\{Fields\Text, Nova};'],
    'mixed with function and const' => ['use Laravel\Nova\Fields\{Number, Text, function helper, const FLAG};'],
    'leading backslash' => ['use \Laravel\Nova\Fields\{Text};'],
]);

it('uses the alias a group import gives the class', function () {
    $result = addTextField(resourceUsing('use Laravel\Nova\Fields\{Text as Field};'));

    expect($result->source)
        ->toContain("Field::make('A', 'a')")
        ->not->toContain('use Laravel\Nova\Fields\Text;');
});

it('still imports a class the group does not provide, exactly once', function () {
    $result = addTextField(resourceUsing('use Laravel\Nova\Fields\{Number, Boolean};'));

    expect(substr_count($result->source, 'use Laravel\Nova\Fields\Text;'))->toBe(1)
        ->and($result->source)->toContain('use Laravel\Nova\Fields\{Number, Boolean};');
    expect(fn () => token_get_all($result->source, TOKEN_PARSE))->not->toThrow(ParseError::class);
});

it('is idempotent when the file uses a group import', function () {
    $first = addTextField(resourceUsing('use Laravel\Nova\Fields\{Text, Number};'));
    $second = addTextField($first->source);

    expect($second->changed)->toBeFalse()->and($second->source)->toBe($first->source);
});

it('keeps plain, aliased and comma-list imports working', function () {
    expect(addTextField(resourceUsing('use Laravel\Nova\Fields\Text;'))->source)
        ->not->toContain('use Laravel\Nova\Fields\Text;' . "\n" . 'use Laravel\Nova\Fields\Text;');

    expect(addTextField(resourceUsing('use Laravel\Nova\Fields\Text as Field;'))->source)->toContain("Field::make('A', 'a')");
    expect(addTextField(resourceUsing('use Illuminate\Support\Str, Laravel\Nova\Fields\Text;'))->source)
        ->toContain("Text::make('A', 'a')")
        ->not->toContain('use Laravel\Nova\Fields\Text;' . "\n\n");
});
