<?php

declare(strict_types=1);

/** Source of a resource file after a build; fails the test if it does not parse. */
function builtResource(string $path): string
{
    $source = file_get_contents($path);

    expect(fn () => token_get_all($source, TOKEN_PARSE))->not->toThrow(ParseError::class);

    return $source;
}

it('writes resource options that differ from Nova defaults and nothing else', function () {
    $draft = $this->draft();
    $draft['nova']['Invoice'] = [
        'navigation' => false,
        'label' => 'Bills',
        'singular' => 'Bill',
        'tableStyle' => 'tight',
        'perPage' => [10, 20],
        'globalSearch' => ['enabled' => false, 'results' => 5, 'link' => 'edit'],
        'searchable' => true,                       // Nova default
        'debounce' => 0.5,                          // Nova default
        'trafficCop' => true,                       // Nova default
    ];

    $this->build($draft);
    $invoice = builtResource($this->novaPath('Invoice'));

    expect($invoice)
        ->toContain('public static $displayInNavigation = false;')
        ->toContain("public static \$tableStyle = 'tight';")
        ->toContain('public static $perPageOptions = [10, 20];')
        ->toContain('public static $globallySearchable = false;')
        ->toContain("public static \$globalSearchLink = 'edit';")
        ->toContain('public static function label(): string')
        ->toContain("return 'Bills';")
        ->toContain('public static function singularLabel(): string')
        ->toContain("return 'Bill';")
        ->not->toContain('$globalSearchResults')    // 5 is Nova's default
        ->not->toContain('$debounce')
        ->not->toContain('$trafficCop')
        ->not->toContain('$searchable ')
        ->and(substr_count($invoice, '@generated blueprint_nova_resources'))->toBeGreaterThan(8);

    // The untouched resources get none of it.
    expect(builtResource($this->novaPath('Payment')))->not->toContain('displayInNavigation')->not->toContain('function label');
});

it('leaves singularLabel to Nova when only label is set', function () {
    $draft = $this->draft();
    $draft['nova']['Invoice'] = ['label' => 'Bills'];

    $this->build($draft);

    expect(builtResource($this->novaPath('Invoice')))
        ->toContain('public static function label(): string')
        ->not->toContain('singularLabel');
});

it('does not write navigation: true, which is already Nova\'s default', function () {
    $draft = $this->draft();
    $draft['nova']['Invoice'] = ['navigation' => true];

    $this->build($draft);

    expect(builtResource($this->novaPath('Invoice')))->not->toContain('displayInNavigation');
});

it('is idempotent with options set', function () {
    $draft = $this->draft();
    $draft['nova']['Invoice'] = ['navigation' => false, 'label' => 'Bills', 'perPage' => [10]];

    $this->build($draft);
    $first = file_get_contents($this->novaPath('Invoice'));

    $this->build($draft);

    expect(file_get_contents($this->novaPath('Invoice')))->toBe($first);
});

it('removes a generated option when it leaves the draft, and keeps the developer\'s code', function () {
    $draft = $this->draft();
    $draft['nova']['Invoice'] = ['navigation' => false, 'label' => 'Bills'];
    $this->build($draft);

    $path = $this->novaPath('Invoice');
    file_put_contents($path, str_replace(
        '...$this->blueprintFields($request),',
        "...\$this->blueprintFields(\$request),\n            Text::make('Custom', 'custom'),",
        file_get_contents($path),
    ));

    $draft['nova']['Invoice'] = ['label' => 'Bills'];
    $this->build($draft);

    expect(builtResource($this->novaPath('Invoice')))
        ->not->toContain('displayInNavigation')
        ->toContain("return 'Bills';")
        ->toContain("Text::make('Custom', 'custom')");

    $draft['nova']['Invoice'] = null;
    $this->build($draft);

    expect(builtResource($this->novaPath('Invoice')))->not->toContain('function label')->toContain("Text::make('Custom', 'custom')");
});

it('changes a generated option in place when the value changes', function () {
    $draft = $this->draft();
    $draft['nova']['Invoice'] = ['label' => 'Bills', 'tableStyle' => 'tight'];
    $this->build($draft);

    $draft['nova']['Invoice'] = ['label' => 'Statements', 'tableStyle' => 'tight'];
    $this->build($draft);

    $invoice = builtResource($this->novaPath('Invoice'));

    expect($invoice)->toContain("return 'Statements';")->not->toContain("'Bills'")
        ->and(substr_count($invoice, 'function label'))->toBe(1)
        ->and(substr_count($invoice, '$tableStyle'))->toBe(1);
});

it('leaves a developer\'s own label() alone and says so', function () {
    mkdir(base_path('app/Nova'), 0777, true);
    file_put_contents($this->novaPath('Invoice'), "<?php\n\nnamespace App\\Nova;\n\nuse Laravel\\Nova\\Resource;\n\nclass Invoice extends Resource\n{\n    public static function label()\n    {\n        return 'Mine';\n    }\n\n    public function fields(\\Laravel\\Nova\\Http\\Requests\\NovaRequest \$request)\n    {\n        return [];\n    }\n}\n");

    $draft = $this->draft();
    $draft['nova']['Invoice'] = ['label' => 'Bills'];
    $this->build($draft);

    expect(builtResource($this->novaPath('Invoice')))->toContain("'Mine'")->not->toContain("'Bills'");
    expect(array_filter($this->notices(), fn (string $n) => str_contains($n, 'Invoice::label()')))->toHaveCount(1);
});

it('rejects invalid option values from the draft', function (array $options, string $message) {
    $draft = $this->draft();
    $draft['nova']['Invoice'] = $options;

    expect(fn () => $this->build($draft))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'navigation as text' => [['navigation' => 'hidden'], 'navigation'],
    'unknown table style' => [['tableStyle' => 'wide'], 'tableStyle'],
    'unknown globalSearch key' => [['globalSearch' => ['bogus' => true]], 'bogus'],
]);
