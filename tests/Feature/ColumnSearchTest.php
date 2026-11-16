<?php

declare(strict_types=1);

// `searchable` on a model column decides what goes into the default $search.

function searchOf(string $resource): string
{
    preg_match('/public static \$search = (\[.*?\]);/s', file_get_contents(base_path("app/Nova/{$resource}.php")), $m);

    return $m[1] ?? '';
}

it('adds a column marked searchable to the default search', function () {
    $draft = $this->draft();
    $draft['models']['Invoice']['notes'] = 'text nullable searchable:true';

    $this->build($draft);

    expect(searchOf('Invoice'))->toBe("['id', 'number', 'notes']");
});

it('treats a bare searchable as searchable:true', function () {
    $draft = $this->draft();
    $draft['models']['Invoice']['notes'] = 'text nullable searchable';

    $this->build($draft);

    expect(searchOf('Invoice'))->toBe("['id', 'number', 'notes']");
});

it('takes a column marked searchable:false out of the default search', function () {
    $draft = $this->draft();
    $draft['models']['Payment']['reference'] = 'string nullable searchable:false';

    $this->build($draft);

    expect(searchOf('Payment'))->toBe("['id']");
});

it('searches a marked column even when the exclusion list or the limit would leave it out', function () {
    config(['blueprint_nova_resources.search_limit' => 1]);
    $draft = $this->draft();
    $draft['models']['Invoice']['color'] = 'string:7 searchable:true';       // excluded by name
    $draft['models']['Invoice']['notes'] = 'text nullable searchable:true';  // text, and past the limit

    $this->build($draft);

    expect(searchOf('Invoice'))->toBe("['id', 'number', 'notes', 'color']");
});

it('still applies the exclusion list to columns that are not marked', function () {
    $draft = $this->draft();
    $draft['models']['Invoice']['color'] = 'string:7';

    $this->build($draft);

    expect(searchOf('Invoice'))->toBe("['id', 'number']");
});

it('lets the draft\'s own search list beat the column marks', function () {
    $draft = $this->draft();
    $draft['models']['Invoice']['notes'] = 'text nullable searchable:true';
    $draft['models']['Invoice']['number'] = 'string:20 unique searchable:false';
    $draft['nova']['Invoice'] = ['search' => ['number']];

    $this->build($draft);

    expect(searchOf('Invoice'))->toBe("['number']");
});

it('reads the mark whatever its case, beside other modifiers and a quoted default', function () {
    $draft = $this->draft();
    $draft['models']['Payment']['reference'] = 'string nullable default:"a b" SEARCHABLE:false';
    $draft['models']['Payment']['amount'] = 'decimal:8,2 normalize:trim searchable';

    $this->build($draft);

    expect(searchOf('Payment'))->toBe("['id', 'amount']");
});

it('only reads the marks of the model a resource belongs to', function () {
    $draft = $this->draft();
    $draft['models']['Invoice']['notes'] = 'text nullable searchable';

    $this->build($draft);

    expect(searchOf('Payment'))->toBe("['id', 'reference']");
});

it('refuses a searchable value it does not know', function (string $value) {
    $draft = $this->draft();
    $draft['models']['Invoice']['notes'] = "text nullable searchable:{$value}";

    expect(fn () => $this->build($draft))
        ->toThrow(InvalidArgumentException::class, 'searchable');
})->with(['maybe', 'yes', '1']);
