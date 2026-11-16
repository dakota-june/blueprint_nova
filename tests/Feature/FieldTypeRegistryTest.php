<?php

declare(strict_types=1);

use Dakotajune\BlueprintNovaResources\Fields\FieldTypeRegistry;

// -- Which Nova field a column becomes ---------------------------------------------------------------------------

it('keeps the built-in mapping when no rule is configured', function () {
    $this->build($this->draft());
    $invoice = file_get_contents($this->novaPath('Invoice'));

    expect($invoice)
        ->toContain("Text::make('Number', 'number')")
        ->toContain("Textarea::make('Notes', 'notes')")
        ->toContain("Code::make('Meta', 'meta')");
});

it('maps a column category to another field from the config', function () {
    config(['blueprint_nova_resources.field_types' => ['text' => 'markdown']]);

    $this->build($this->draft());

    expect(file_get_contents($this->novaPath('Invoice')))
        ->toContain("Markdown::make('Notes', 'notes')")
        ->not->toContain('Textarea::make');
});

it('maps a column by its name from the config', function () {
    config(['blueprint_nova_resources.name_hints' => ['markdown' => ['notes'], 'url' => ['website']]]);

    $this->build($this->draft());

    expect(file_get_contents($this->novaPath('Invoice')))->toContain("Markdown::make('Notes', 'notes')");
    expect(file_get_contents($this->novaPath('User')))->toContain("URL::make('Website', 'website')");
});

it('lets a name rule beat a type rule', function () {
    config([
        'blueprint_nova_resources.field_types' => ['text' => 'markdown'],
        'blueprint_nova_resources.name_hints' => ['trix' => ['notes']],
    ]);

    $this->build($this->draft());

    expect(file_get_contents($this->novaPath('Invoice')))->toContain("Trix::make('Notes', 'notes')");
});

it('lets the draft override beat every registry rule', function () {
    config(['blueprint_nova_resources.field_types' => ['text' => 'markdown']]);
    $draft = $this->draft();
    $draft['nova']['Invoice'] = ['fields' => ['notes' => 'textarea']];

    $this->build($draft);

    expect(file_get_contents($this->novaPath('Invoice')))->toContain("Textarea::make('Notes', 'notes')");
});

it('takes rules registered in code, from the same registry the build uses', function () {
    $this->app->make(FieldTypeRegistry::class)
        ->type('text', 'markdown')
        ->name('reference', 'textarea');

    $this->build($this->draft());

    expect(file_get_contents($this->novaPath('Invoice')))->toContain("Markdown::make('Notes', 'notes')");
    expect(file_get_contents($this->novaPath('Payment')))->toContain("Textarea::make('Reference', 'reference')");
});

it('never remaps the primary key or a foreign key', function () {
    config(['blueprint_nova_resources.field_types' => ['integer' => 'currency']]);

    $this->build($this->draft());
    $payment = file_get_contents($this->novaPath('Payment'));

    expect($payment)->toContain('ID::make()')->toContain("BelongsTo::make('Invoice'");
});

it('refuses a rule that names a field type the package does not know', function (array $config) {
    config($config);

    expect(fn () => $this->build($this->draft()))
        ->toThrow(InvalidArgumentException::class, 'not a Nova field type');
})->with([
    'field_types' => [['blueprint_nova_resources.field_types' => ['text' => 'wysiwyg']]],
    'name_hints' => [['blueprint_nova_resources.name_hints' => ['wysiwyg' => ['notes']]]],
]);

it('refuses a rule registered in code that names an unknown field type', function () {
    expect(fn () => $this->app->make(FieldTypeRegistry::class)->type('text', 'wysiwyg'))
        ->toThrow(InvalidArgumentException::class, 'Valid types');
});

// -- Scaffolding resources from models -------------------------------------------------------------------------

it('scaffolds a resource for every model when the draft has no nova section at all', function () {
    config(['blueprint_nova_resources.auto_resources' => true]);
    $draft = $this->draft();
    unset($draft['nova']);

    $out = $this->build($draft);

    expect(array_column($out['created'], 1))
        ->toContain('app/Nova/Invoice.php', 'app/Nova/Payment.php', 'app/Nova/User.php');
});

it('scaffolds nothing without auto_resources and without a nova section', function () {
    $draft = $this->draft();
    unset($draft['nova']);

    expect($this->build($draft)['created'])->toBeEmpty();
});

it('leaves out the models in auto_resources_except', function () {
    config([
        'blueprint_nova_resources.auto_resources' => true,
        'blueprint_nova_resources.auto_resources_except' => ['User'],
    ]);
    $draft = $this->draft();
    unset($draft['nova']);

    $out = $this->build($draft);

    expect(array_column($out['created'], 1))
        ->toContain('app/Nova/Invoice.php', 'app/Nova/Payment.php')
        ->not->toContain('app/Nova/User.php');
});

it('still generates an excepted model that the draft lists under nova', function () {
    config([
        'blueprint_nova_resources.auto_resources' => true,
        'blueprint_nova_resources.auto_resources_except' => ['User'],
    ]);
    $draft = $this->draft();
    $draft['nova'] = ['User' => null];

    $out = $this->build($draft);

    expect(array_column($out['created'], 1))->toContain('app/Nova/User.php', 'app/Nova/Invoice.php');
});

it('applies the draft\'s options to a model that is also scaffolded', function () {
    config(['blueprint_nova_resources.auto_resources' => true]);
    $draft = $this->draft();
    $draft['nova'] = ['Payment' => ['group' => 'Billing']];

    $this->build($draft);

    expect(file_get_contents($this->novaPath('Payment')))->toContain("public static \$group = 'Billing';");
    expect(file_get_contents($this->novaPath('Invoice')))->not->toContain('$group');
});

it('still switches the package off with nova: false under auto_resources', function () {
    config(['blueprint_nova_resources.auto_resources' => true]);
    $draft = $this->draft();
    $draft['nova'] = false;

    expect($this->build($draft)['created'])->toBeEmpty();
});
