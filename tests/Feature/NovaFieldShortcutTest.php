<?php

declare(strict_types=1);

// `novaField:<type>` on a model column: a shortcut for `fields: column: <type>` under `nova:`.

function novaSource(string $resource): string
{
    return file_get_contents(base_path("app/Nova/{$resource}.php"));
}

it('turns a column into the field type its novaField names', function () {
    $draft = $this->draft();
    $draft['models']['Invoice']['notes'] = 'text nullable novaField:markdown';

    $this->build($draft);

    expect(novaSource('Invoice'))
        ->toContain("Markdown::make('Notes', 'notes')")
        ->not->toContain('Textarea::make');
});

it('reads the field type whatever its case', function () {
    $draft = $this->draft();
    $draft['models']['Invoice']['notes'] = 'text nullable normalize:trim NOVAFIELD:Markdown';

    $this->build($draft);

    expect(novaSource('Invoice'))->toContain("Markdown::make('Notes', 'notes')");
});

it('lets the nova section beat the shortcut', function () {
    $draft = $this->draft();
    $draft['models']['Invoice']['notes'] = 'text nullable novaField:markdown';
    $draft['nova']['Invoice'] = ['fields' => ['notes' => 'trix']];

    $this->build($draft);

    expect(novaSource('Invoice'))->toContain("Trix::make('Notes', 'notes')")->not->toContain('Markdown::make');
});

it('keeps the other options written under nova for the same column', function () {
    $draft = $this->draft();
    $draft['models']['Invoice']['notes'] = 'text nullable novaField:markdown';
    $draft['nova']['Invoice'] = ['fields' => ['notes' => ['label' => 'About', 'hide' => ['index']]]];

    $this->build($draft);

    expect(novaSource('Invoice'))
        ->toContain("Markdown::make('About', 'notes')")
        ->toContain('->hideFromIndex()');
});

it('leaves a column out with novaField:false', function () {
    $draft = $this->draft();
    $draft['models']['Invoice']['meta'] = 'json nullable novaField:false';

    $this->build($draft);

    expect(novaSource('Invoice'))->not->toContain("'meta'");
});

it('refuses a field type it does not know', function () {
    $draft = $this->draft();
    $draft['models']['Invoice']['notes'] = 'text nullable novaField:wysiwyg';

    expect(fn () => $this->build($draft))
        ->toThrow(InvalidArgumentException::class, 'novaField:wysiwyg');
});

it('works together with searchable and under auto_resources with no nova section', function () {
    config(['blueprint_nova_resources.auto_resources' => true]);
    $draft = $this->draft();
    unset($draft['nova']);
    $draft['models']['Invoice']['notes'] = 'text nullable searchable novaField:markdown';

    $this->build($draft);

    expect(novaSource('Invoice'))
        ->toContain("Markdown::make('Notes', 'notes')")
        ->toContain("public static \$search = ['id', 'number', 'notes'];");
});

it('only reads the shortcut of the model a resource belongs to', function () {
    $draft = $this->draft();
    $draft['models']['Invoice']['notes'] = 'text nullable novaField:markdown';

    $this->build($draft);

    expect(novaSource('Payment'))->not->toContain('Markdown');
});
