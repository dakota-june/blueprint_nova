<?php

declare(strict_types=1);

use Dakotajune\BlueprintNovaResources\Support\ResourceClassEditor;
use Dakotajune\BlueprintNovaResources\Support\ResourceMemberBuilder;
use Illuminate\Filesystem\Filesystem;

/** A hand-written resource with a trait at the top, like Nova's own User resource. */
function developerUser(string $fieldsBody = 'return [];', string $extraProperties = ''): string
{
    return "<?php\n\nnamespace App\\Nova;\n\nuse Laravel\\Nova\\Resource;\n\nclass User extends Resource\n{\n    use \\Illuminate\\Support\\Traits\\Macroable;\n\n"
        . "    public static \$model = \\App\\Models\\User::class;\n\n"
        . "    public static \$title = 'name';\n{$extraProperties}\n"
        . "    public function fields(\\Laravel\\Nova\\Http\\Requests\\NovaRequest \$request)\n    {\n        {$fieldsBody}\n    }\n}\n";
}

function writeDeveloperResource(string $path, string $source): void
{
    (new Filesystem())->ensureDirectoryExists(dirname($path));
    file_put_contents($path, $source);
}

// -- Placement of new properties ---------------------------------------------------------------------------------

it('places new properties after the class\'s existing properties, not above its trait', function () {
    $draft = $this->draft();
    $draft['nova']['User'] = ['group' => 'Admin', 'clickAction' => 'edit'];
    writeDeveloperResource($this->novaPath('User'), developerUser());

    $this->build($draft);
    $source = file_get_contents($this->novaPath('User'));

    $trait = strpos($source, 'use \Illuminate\Support\Traits\Macroable;');
    $title = strpos($source, "public static \$title = 'name';");
    $group = strpos($source, "public static \$group = 'Admin';");
    $click = strpos($source, "public static \$clickAction = 'edit';");

    expect($trait)->toBeLessThan($title)
        ->and($title)->toBeLessThan($group)
        ->and($group)->toBeLessThan($click)
        ->and($click)->toBeLessThan(strpos($source, 'public function fields('));
    expect(fn () => token_get_all($source, TOKEN_PARSE))->not->toThrow(ParseError::class);
});

it('keeps a class with no properties working: they go right after the brace', function () {
    $editor = new ResourceClassEditor();
    $source = "<?php\n\nnamespace App\\Nova;\n\nclass Invoice extends Resource\n{\n    public function fields(\$request)\n    {\n        return [];\n    }\n}\n";

    $result = $editor->update($source, 'Invoice', ['title' => ResourceMemberBuilder::property('title', 'Title.', "'number'", 'string')], []);

    expect(strpos($result->source, '$title'))->toBeLessThan(strpos($result->source, 'function fields('));
    expect(fn () => token_get_all($result->source, TOKEN_PARSE))->not->toThrow(ParseError::class);
});

it('is idempotent after placing properties next to existing ones', function () {
    $draft = $this->draft();
    $draft['nova']['User'] = ['group' => 'Admin'];
    writeDeveloperResource($this->novaPath('User'), developerUser());

    $this->build($draft);
    $first = file_get_contents($this->novaPath('User'));
    $this->build($draft);

    expect(file_get_contents($this->novaPath('User')))->toBe($first);
});

// -- Notices for untagged members --------------------------------------------------------------------------------

it('stays quiet about an untagged member that already says what would have been generated', function () {
    writeDeveloperResource($this->novaPath('User'), developerUser());

    $this->build($this->draft());
    $notices = $this->notices();

    // $model is identical to what we would write; $title ('name') differs from the generated 'email'? either way:
    expect(array_filter($notices, fn (string $n) => str_contains($n, 'User::$model')))->toBeEmpty();
});

it('still reports an untagged member whose value differs', function () {
    writeDeveloperResource($this->novaPath('User'), developerUser());
    $draft = $this->draft();
    $draft['nova']['User'] = ['title' => 'email'];

    $this->build($draft);

    expect(array_filter($this->notices(), fn (string $n) => str_contains($n, 'User::$title')))->toHaveCount(1);
});

it('treats comments, spacing and a trailing comma as the same declaration', function () {
    $editor = new ResourceClassEditor();
    $source = "<?php\n\nnamespace App\\Nova;\n\nclass Invoice extends Resource\n{\n    /** mine */\n    public static \$search = [\n        'id',\n        'number',\n    ];\n}\n";

    $result = $editor->update($source, 'Invoice', [
        'search' => ResourceMemberBuilder::property('search', 'Search.', "['id', 'number']", 'array<int, string>'),
    ], []);

    expect($result->notices)->toBeEmpty()->and($result->changed)->toBeFalse();
});

// -- fields() that never calls blueprintFields() ---------------------------------------------------------------

it('warns when an existing resource\'s fields() does not call blueprintFields()', function () {
    writeDeveloperResource($this->novaPath('User'), developerUser('return [];'));

    $this->build($this->draft());

    expect(array_filter($this->notices(), fn (string $n) => str_contains($n, 'does not call blueprintFields()')))->toHaveCount(1);
});

it('does not warn when fields() spreads blueprintFields()', function () {
    writeDeveloperResource($this->novaPath('User'), developerUser('return [...$this->blueprintFields($request)];'));

    $this->build($this->draft());

    expect(array_filter($this->notices(), fn (string $n) => str_contains($n, 'does not call blueprintFields()')))->toBeEmpty();
});

it('does not warn for a resource the generator just created', function () {
    $this->build($this->draft());

    expect(array_filter($this->notices(), fn (string $n) => str_contains($n, 'does not call blueprintFields()')))->toBeEmpty();
});

// -- A name that is both a column and a relationship -----------------------------------------------------------------

it('refuses an override for a name that is both a column and a relationship', function () {
    $draft = $this->draft();
    $draft['models']['Invoice']['payments'] = 'text nullable';      // Invoice also has hasMany Payment -> payments
    $draft['nova']['Invoice'] = ['fields' => ['payments' => ['label' => 'Pay']]];

    expect(fn () => $this->build($draft))
        ->toThrow(InvalidArgumentException::class, 'both a column and a relationship');
});

it('only objects to the clash when that name is overridden', function () {
    $draft = $this->draft();
    $draft['models']['Invoice']['payments'] = 'text nullable';

    expect(fn () => $this->build($draft))->not->toThrow(InvalidArgumentException::class);
});

// -- Default $search ---------------------------------------------------------------------------------------------

it('leaves formats and identifiers out of the default search', function () {
    $draft = $this->draft();
    $draft['models']['Invoice']['color'] = 'string:7';
    $draft['models']['Invoice']['locale'] = 'string:5';
    $this->build($draft);

    expect(file_get_contents($this->novaPath('Invoice')))->toContain("public static \$search = ['id', 'number'];");
});

it('lets the config decide what stays out of the default search', function () {
    config(['blueprint_nova_resources.search_exclude' => []]);
    $draft = $this->draft();
    $draft['models']['Invoice']['color'] = 'string:7';
    $this->build($draft);

    expect(file_get_contents($this->novaPath('Invoice')))->toContain("public static \$search = ['id', 'number', 'color'];");
});

it('falls back to the secrets-only list when the config predates search_exclude', function () {
    $config = config('blueprint_nova_resources');
    unset($config['search_exclude']);                      // as in a config published before the option existed
    config(['blueprint_nova_resources' => $config]);
    $draft = $this->draft();
    $draft['models']['Invoice']['color'] = 'string:7';
    $draft['models']['Invoice']['api_token'] = 'string:40';
    $this->build($draft);

    expect(file_get_contents($this->novaPath('Invoice')))->toContain("public static \$search = ['id', 'number', 'color'];");
});

it('never searches a draft\'s own search list through the exclusions', function () {
    $draft = $this->draft();
    $draft['models']['Invoice']['color'] = 'string:7';
    $draft['nova']['Invoice'] = ['search' => ['number', 'color']];
    $this->build($draft);

    expect(file_get_contents($this->novaPath('Invoice')))->toContain("public static \$search = ['number', 'color'];");
});

// -- `as` on a relationship, and empty search entries ----------------------------------------------------------------

it('refuses `as` on a relationship, because its field type is fixed', function () {
    $draft = $this->draft();
    $draft['nova']['Invoice'] = ['fields' => ['payments' => ['as' => 'text']]];

    expect(fn () => $this->build($draft))
        ->toThrow(InvalidArgumentException::class, 'is a relationship, so `as` does not apply');
});

it('still accepts label and hide on a relationship', function () {
    $draft = $this->draft();
    $draft['nova']['Invoice'] = ['fields' => ['payments' => ['label' => 'Pay', 'hide' => ['index']]]];

    expect(fn () => $this->build($draft))->not->toThrow(InvalidArgumentException::class);
});

it('rejects an empty entry in a draft search list', function (mixed $search) {
    $draft = $this->draft();
    $draft['nova']['Invoice'] = ['search' => $search];

    expect(fn () => $this->build($draft))
        ->toThrow(InvalidArgumentException::class, '`search` must list column names');
})->with([
    'null entry' => [[null]],
    'null among columns' => [['number', null]],
    'nested list' => [[['number']]],
]);
