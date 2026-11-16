<?php

declare(strict_types=1);

function lints(string $source): bool
{
    try {
        token_get_all($source, TOKEN_PARSE);

        return true;
    } catch (ParseError) {
        return false;
    }
}

it('generates a lint-clean resource per nova entry', function () {
    $this->build($this->draft());

    foreach (['Invoice', 'Payment', 'User'] as $name) {
        expect(lints(file_get_contents($this->novaPath($name))))->toBeTrue();
    }

    $invoice = file_get_contents($this->novaPath('Invoice'));
    expect($invoice)
        ->toContain('public static $model = \App\Models\Invoice::class;')
        ->toContain("public static \$search = ['id', 'number'];")
        ->toContain('ID::make()->sortable()')
        ->toContain("HasMany::make('Payments', 'payments', Payment::class)")
        ->toContain('...$this->blueprintFields($request)');
});

it('maps columns, belongsTo, passwords and overrides', function () {
    $this->build($this->draft());

    expect(file_get_contents($this->novaPath('Payment')))
        ->toContain("BelongsTo::make('Invoice', 'invoice', Invoice::class)")
        ->toContain("public static \$group = 'Billing';")
        ->toContain("Boolean::make('Settled', 'paid')");

    expect(file_get_contents($this->novaPath('User')))
        ->toContain('Email::make(')
        ->toContain('Password::make(')
        ->toContain('onlyOnForms()');
});

it('is idempotent and keeps developer code across rebuilds', function () {
    $this->build($this->draft());
    $path = $this->novaPath('Invoice');
    file_put_contents($path, str_replace(
        '...$this->blueprintFields($request),',
        "...\$this->blueprintFields(\$request),\n            Text::make('Custom', 'custom'),",
        file_get_contents($path),
    ));
    $before = file_get_contents($path);

    $this->build($this->draft());
    expect(file_get_contents($path))->toBe($before);

    $draft = $this->draft();
    $draft['models']['Invoice']['due_date'] = 'date nullable';
    $this->build($draft);

    expect(file_get_contents($path))
        ->toContain("Date::make('Due Date', 'due_date')")
        ->toContain("Text::make('Custom', 'custom')")
        ->toContain('use Laravel\Nova\Fields\Date;');
});

it('never reports a pre-existing developer file as created', function () {
    mkdir(base_path('app/Nova'), 0777, true);
    file_put_contents($this->novaPath('User'), "<?php\n\nnamespace App\\Nova;\n\nuse Laravel\\Nova\\Resource;\n\nclass User extends Resource\n{\n    public static \$model = \\App\\Models\\User::class;\n\n    public function fields(\\Laravel\\Nova\\Http\\Requests\\NovaRequest \$request)\n    {\n        return [];\n    }\n}\n");

    $out = $this->build($this->draft());

    expect(array_column($out['updated'], 1))->toContain('app/Nova/User.php')
        ->and(array_column($out['created'], 1))->not->toContain('app/Nova/User.php');
    expect(substr_count(file_get_contents($this->novaPath('User')), '$model'))->toBe(1);
});

it('rejects invalid draft options', function (array $nova, string $message) {
    $draft = $this->draft();
    $draft['nova']['Invoice'] = $nova;

    expect(fn () => $this->build($draft))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown option' => [['bogus' => 1], 'bogus'],
    'planned option' => [['metrics' => ['X']], 'metrics'],
    'unknown field' => [['fields' => ['nope' => 'text']], 'nope'],
    'unknown type' => [['fields' => ['number' => 'wibble']], 'wibble'],
    'bad title' => [['title' => 'missing'], 'missing'],
    'bad relation' => [['relations' => ['ghost']], 'ghost'],
]);

it('falls back to a plain field when the related resource does not exist', function () {
    $draft = $this->draft();
    $draft['nova'] = ['Payment' => null];
    $this->build($draft);

    $payment = file_get_contents($this->novaPath('Payment'));
    expect($payment)->not->toContain('BelongsTo::make')->and(lints($payment))->toBeTrue();
});

it('honours auto_resources and per-model opt-out', function () {
    config(['blueprint_nova_resources.auto_resources' => true]);
    $draft = $this->draft();
    $draft['nova'] = ['Payment' => null, 'User' => false];

    $out = $this->build($draft);

    expect(array_column($out['created'], 1))->toContain('app/Nova/Invoice.php', 'app/Nova/Payment.php')
        ->not->toContain('app/Nova/User.php');
});

it('reports files as [label, path] pairs, the way Blueprint prints and records them', function () {
    $out = $this->build($this->draft());

    foreach (['created', 'updated'] as $action) {
        foreach ($out[$action] as $entry) {
            expect($entry)->toBeArray()->toHaveCount(2)
                ->and($entry[0])->toBeString()
                ->and($entry[1])->toBeString();
        }
    }

    // Blueprint's Builder writes array_column($entries, 1) into `.blueprint`, which `blueprint:erase` deletes from.
    expect(array_column($out['created'], 1))->toContain('app/Nova/Invoice.php', 'app/Nova/Payment.php', 'app/Nova/User.php');
});

it('only asks for registration when the provider does not already register app/Nova', function (string $provider, int $expected) {
    mkdir(base_path('app/Providers'), 0777, true);
    file_put_contents(base_path('app/Providers/NovaServiceProvider.php'), $provider);

    $this->build($this->draft());

    expect(array_filter($this->notices(), fn (string $n) => str_contains($n, 'may need registering')))->toHaveCount($expected);
})->with([
    'extends Nova, no override' => ["<?php\n\nnamespace App\\Providers;\n\nuse Laravel\\Nova\\NovaApplicationServiceProvider;\n\nclass NovaServiceProvider extends NovaApplicationServiceProvider\n{\n}\n", 0],
    'extends Nova by full name' => ["<?php\n\nnamespace App\\Providers;\n\nclass NovaServiceProvider extends \\Laravel\\Nova\\NovaApplicationServiceProvider\n{\n}\n", 0],
    'overrides resources() with resourcesIn' => ["<?php\n\nnamespace App\\Providers;\n\nuse Laravel\\Nova\\Nova;\nuse Laravel\\Nova\\NovaApplicationServiceProvider;\n\nclass NovaServiceProvider extends NovaApplicationServiceProvider\n{\n    protected function resources(): void\n    {\n        Nova::resourcesIn(app_path('Nova'));\n    }\n}\n", 0],
    'overrides resources() and lists only User' => ["<?php\n\nnamespace App\\Providers;\n\nuse Laravel\\Nova\\Nova;\nuse Laravel\\Nova\\NovaApplicationServiceProvider;\n\nclass NovaServiceProvider extends NovaApplicationServiceProvider\n{\n    protected function resources(): void\n    {\n        Nova::resources([\\App\\Nova\\User::class]);\n    }\n}\n", 2],
    'not a Nova provider' => ["<?php\n\nnamespace App\\Providers;\n\nuse Illuminate\\Support\\ServiceProvider;\n\nclass NovaServiceProvider extends ServiceProvider\n{\n}\n", 3],
]);
