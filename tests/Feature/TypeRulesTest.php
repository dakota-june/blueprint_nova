<?php

declare(strict_types=1);

// Validation rules a column's data type implies, so a value the database would refuse is refused by the form first.

function invoiceSource(): string
{
    return file_get_contents(base_path('app/Nova/Invoice.php'));
}

it('limits an integer column to what its type can hold', function () {
    $draft = $this->draft();
    $draft['models']['Invoice']['quantity'] = 'integer';
    $draft['models']['Invoice']['level'] = 'tinyInteger unsigned';
    $draft['models']['Invoice']['stock'] = 'smallInteger';

    $this->build($draft);

    expect(invoiceSource())
        ->toContain("rules('required', 'integer', 'between:-2147483648,2147483647')")
        ->toContain("rules('required', 'integer', 'between:0,255')")
        ->toContain("rules('required', 'integer', 'between:-32768,32767')");
});

it('limits a decimal column to its places and its size', function () {
    $this->build($this->draft());      // total: decimal:8,2

    expect(invoiceSource())
        ->toContain("rules('required', 'numeric', 'decimal:0,2', 'between:-999999.99,999999.99')");
});

it('treats a decimal with no precision the way Laravel does: decimal(8, 2)', function () {
    $draft = $this->draft();
    $draft['models']['Invoice']['price'] = 'decimal';

    $this->build($draft);

    expect(invoiceSource())
        ->toMatch("/Number::make\('Price', 'price'\)\s*->step\('0\.01'\)/")
        ->toContain("'decimal:0,2', 'between:-999999.99,999999.99'");
});

it('keeps an unsigned decimal from going negative', function () {
    $draft = $this->draft();
    $draft['models']['Invoice']['rate'] = 'decimal:5,3 unsigned';

    $this->build($draft);

    expect(invoiceSource())->toContain("'decimal:0,3', 'between:0,99.999'");
});

it('asks for valid JSON in a json column, and a date in a date column', function () {
    $this->build($this->draft());      // meta: json nullable, issued_at: timestamp nullable

    expect(invoiceSource())
        ->toContain("Code::make('Meta', 'meta')->json()->nullable()->rules('json')")
        ->toContain("->rules('date')");
});

it('asks for the format a uuid column holds', function () {
    $draft = $this->draft();
    $draft['models']['Invoice']['public_ref'] = 'uuid';

    $this->build($draft);

    expect(invoiceSource())->toContain("rules('required', 'uuid')");
});

it('leaves a foreign key to its BelongsTo field', function () {
    $this->build($this->draft());

    expect(file_get_contents(base_path('app/Nova/Payment.php')))
        ->toContain("BelongsTo::make('Invoice', 'invoice', Invoice::class)")
        ->not->toContain("'invoice_id'");
});

it('keeps only the basic rules when type_rules is off', function () {
    config(['blueprint_nova_resources.type_rules' => false]);
    $draft = $this->draft();
    $draft['models']['Invoice']['quantity'] = 'integer';

    $this->build($draft);

    expect(invoiceSource())
        ->toContain("rules('required', 'integer')")
        ->toContain("rules('required', 'numeric')")
        ->not->toContain('between:')
        ->not->toContain("'json'");
});

it('lets the draft replace the derived rules', function () {
    $draft = $this->draft();
    $draft['models']['Invoice']['quantity'] = 'integer';
    $draft['nova']['Invoice'] = ['fields' => ['quantity' => ['rules' => ['min:1']]]];

    $this->build($draft);

    expect(invoiceSource())
        ->toContain("Number::make('Quantity', 'quantity')")
        ->toContain("->rules('min:1')")
        ->not->toContain('-2147483648');
});
