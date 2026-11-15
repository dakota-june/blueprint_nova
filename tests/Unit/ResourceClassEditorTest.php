<?php

declare(strict_types=1);

use Dakotajune\BlueprintNovaResources\Support\ResourceClassEditor;
use Dakotajune\BlueprintNovaResources\Support\ResourceMemberBuilder;

function seedResource(): string
{
    return "<?php\n\nnamespace App\\Nova;\n\nclass Invoice extends Resource\n{\n    public function fields(\$request)\n    {\n        return [];\n    }\n}\n";
}

function fieldsMethod(string $body): string
{
    return ResourceMemberBuilder::fields([$body]);
}

it('adds a tagged method and a tagged property without touching fields()', function () {
    $editor = new ResourceClassEditor();
    $result = $editor->update(
        seedResource(),
        'Invoice',
        ['title' => ResourceMemberBuilder::property('title', 'Title.', "'number'", 'string')],
        ['blueprintFields' => fieldsMethod("Text::make('A', 'a')")],
    );

    expect($result->changed)->toBeTrue()
        ->and($result->source)->toContain('return [];')
        ->toContain("public static \$title = 'number';")
        ->toContain('function blueprintFields(')
        ->toContain('use Laravel\Nova\Http\Requests\NovaRequest;');
    expect(token_get_all($result->source, TOKEN_PARSE))->not->toBeEmpty();
});

it('is idempotent', function () {
    $editor = new ResourceClassEditor();
    $args = ['Invoice', [], ['blueprintFields' => fieldsMethod("Text::make('A', 'a')")]];

    $first = $editor->update(seedResource(), ...$args);
    $second = $editor->update($first->source, ...$args);

    expect($second->changed)->toBeFalse()->and($second->source)->toBe($first->source);
});

it('leaves an untagged member of the same name alone and says so', function () {
    $source = str_replace('{', "{\n    public static \$title = 'mine';\n", seedResource());
    $result = (new ResourceClassEditor())->update(
        $source,
        'Invoice',
        ['title' => ResourceMemberBuilder::property('title', 'Title.', "'number'", 'string')],
        [],
    );

    expect($result->source)->toContain("'mine'")->not->toContain("'number'");
    expect($result->notices)->not->toBeEmpty();
});

it('strips only generated members and detects developer content', function () {
    $editor = new ResourceClassEditor();
    $built = $editor->update(seedResource(), 'Invoice', [], ['blueprintFields' => fieldsMethod("Text::make('A', 'a')")]);
    $seed = $editor->methodHash($built->source, 'Invoice', 'fields');

    $stripped = $editor->stripGenerated($built->source, 'Invoice');

    expect($stripped)->not->toContain('blueprintFields')->toContain('function fields(');
    expect($editor->hasDeveloperContent($stripped, 'Invoice', $seed))->toBeFalse();

    $edited = str_replace('return [];', "return [Text::make('X')];", $stripped);
    expect($editor->hasDeveloperContent($edited, 'Invoice', $seed))->toBeTrue();
});
