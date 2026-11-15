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

    expect($out['updated'])->toContain('app/Nova/User.php')
        ->and($out['created'])->not->toContain('app/Nova/User.php');
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

    expect($out['created'])->toContain('app/Nova/Invoice.php', 'app/Nova/Payment.php')
        ->not->toContain('app/Nova/User.php');
});
