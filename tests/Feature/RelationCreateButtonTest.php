<?php

declare(strict_types=1);

// Nova's "create a new related record" button on a BelongsTo: `novaCreate` on the foreign key column,
// `create: true` under nova:, or the create_relation_button config for every BelongsTo.

function paymentSource(): string
{
    return file_get_contents(base_path('app/Nova/Payment.php'));
}

it('writes no create button unless asked', function () {
    $this->build($this->draft());

    expect(paymentSource())->toContain("BelongsTo::make('Invoice', 'invoice', Invoice::class)")
        ->not->toContain('showCreateRelationButton');
});

it('adds the button from novaCreate on the foreign key column', function (string $modifier) {
    $draft = $this->draft();
    $draft['models']['Payment']['invoice_id'] = "id foreign {$modifier}";

    $this->build($draft);

    expect(paymentSource())->toContain('->showCreateRelationButton()');
})->with(['bare' => ['novaCreate'], 'true' => ['novaCreate:true'], 'any case' => ['NOVACREATE:True']]);

it('adds the button from create: true under nova, by relationship name or by column', function (string $name) {
    $draft = $this->draft();
    $draft['nova']['Payment'] = ['fields' => [$name => ['create' => true]]];

    $this->build($draft);

    expect(paymentSource())->toContain('->showCreateRelationButton()');
})->with(['invoice', 'invoice_id']);

it('puts the button on every BelongsTo when the config says so, and novaCreate:false opts one out', function () {
    config(['blueprint_nova_resources.create_relation_button' => true]);
    $draft = $this->draft();

    $this->build($draft);
    expect(paymentSource())->toContain('->showCreateRelationButton()');

    $draft['models']['Payment']['invoice_id'] = 'id foreign novaCreate:false';
    $this->build($draft);
    expect(paymentSource())->not->toContain('showCreateRelationButton');
});

it('keeps a shortcut on the column when the relationship is also configured by name', function () {
    $draft = $this->draft();
    $draft['models']['Payment']['invoice_id'] = 'id foreign novaCreate';
    $draft['nova']['Payment'] = ['fields' => ['invoice' => ['label' => 'Parent invoice']]];

    $this->build($draft);

    expect(paymentSource())
        ->toContain("BelongsTo::make('Parent invoice', 'invoice', Invoice::class)")
        ->toContain('->showCreateRelationButton()');
});

it('refuses create on a column that is not a belongsTo foreign key', function () {
    $draft = $this->draft();
    $draft['models']['Invoice']['number'] = 'string:20 unique novaCreate';

    expect(fn () => $this->build($draft))
        ->toThrow(InvalidArgumentException::class, 'only applies to a belongsTo');
});

it('refuses create on a hasMany relationship', function () {
    $draft = $this->draft();
    $draft['nova']['Invoice'] = ['fields' => ['payments' => ['create' => true]]];

    expect(fn () => $this->build($draft))
        ->toThrow(InvalidArgumentException::class, 'only applies to a belongsTo');
});

it('refuses a novaCreate value it does not know', function () {
    $draft = $this->draft();
    $draft['models']['Payment']['invoice_id'] = 'id foreign novaCreate:maybe';

    expect(fn () => $this->build($draft))
        ->toThrow(InvalidArgumentException::class, 'novaCreate');
});
