<?php

declare(strict_types=1);

use Dakotajune\BlueprintNovaResources\Lexers\NovaResourceLexer;
use Dakotajune\BlueprintNovaResources\Support\ResourceOptions;

it('reads only the options the draft sets and normalises them', function () {
    $settings = ResourceOptions::parse('Invoice', [
        'group' => 'Billing',                       // not one of ours: ignored here
        'navigation' => false,
        'label' => ' Bills ',
        'tableStyle' => 'Tight',
        'debounce' => 1,
        'perPage' => 10,
        'globalSearch' => ['results' => 10, 'link' => 'edit'],
    ]);

    // Order follows ResourceOptions::KEYS, not the draft.
    expect($settings)->toBe([
        'navigation' => false,
        'label' => 'Bills',
        'globalSearch.results' => 10,
        'globalSearch.link' => 'edit',
        'tableStyle' => 'tight',
        'debounce' => 1.0,
        'perPage' => [10],
    ]);
});

it('treats globalSearch true as "leave it alone" and false as enabled: false', function () {
    expect(ResourceOptions::parse('Invoice', ['globalSearch' => true]))->toBe([])
        ->and(ResourceOptions::parse('Invoice', ['globalSearch' => false]))->toBe(['globalSearch.enabled' => false])
        ->and(ResourceOptions::parse('Invoice', ['globalSearch' => ['enabled' => null]]))->toBe([]);
});

it('keeps only values that differ from Nova defaults', function () {
    $differing = ResourceOptions::differing([
        'navigation' => true,                        // Nova default
        'searchable' => false,
        'showColumnBorders' => false,                // Nova default
        'debounce' => 0.5,                           // Nova default
        'perPage' => [25, 50, 100],                  // Nova default
        'tableStyle' => 'default',                   // Nova default
        'label' => 'Bills',                          // no default: always kept when set
        'perPageViaRelationship' => [5],             // no default: always kept when set
    ]);

    expect($differing)->toBe([
        'label' => 'Bills',
        'searchable' => false,
        'perPageViaRelationship' => [5],
    ]);
});

it('returns the differing settings in table order whatever order they arrive in', function () {
    $forward = ResourceOptions::differing(['navigation' => false, 'label' => 'Bills', 'trafficCop' => false]);
    $backward = ResourceOptions::differing(['trafficCop' => false, 'label' => 'Bills', 'navigation' => false]);

    expect(array_keys($backward))->toBe(array_keys($forward))->toBe(['navigation', 'label', 'trafficCop']);
});

it('inverts the default for showColumnBorders: true is what gets written', function () {
    expect(ResourceOptions::differing(['showColumnBorders' => true]))->toBe(['showColumnBorders' => true]);
});

it('renders normalised values as PHP source', function () {
    expect(ResourceOptions::literal('navigation', false))->toBe('false')
        ->and(ResourceOptions::literal('globalSearch.results', 10))->toBe('10')
        ->and(ResourceOptions::literal('debounce', 1.0))->toBe('1.0')
        ->and(ResourceOptions::literal('debounce', 0.25))->toBe('0.25')
        ->and(ResourceOptions::literal('perPage', [10, 20]))->toBe('[10, 20]')
        ->and(ResourceOptions::literal('tableStyle', 'tight'))->toBe("'tight'")
        ->and(ResourceOptions::literal('label', "O'Brien"))->toBe("'O\\'Brien'");
});

it('documents nullable properties as nullable', function () {
    expect(ResourceOptions::varType('navigation'))->toBe('bool')
        ->and(ResourceOptions::varType('perPage'))->toBe('array<int, int>')
        ->and(ResourceOptions::varType('perPageViaRelationship'))->toBe('array<int, int>|null')
        ->and(ResourceOptions::varType('relatableSearchResults'))->toBe('int|null')
        ->and(ResourceOptions::varType('clickAction'))->toBe('string');
});

it('is accepted by the lexer for every key in the table', function () {
    foreach (ResourceOptions::KEYS as $key) {
        expect(NovaResourceLexer::OPTIONS)->toContain($key);
    }
});

it('rejects invalid option values with a message naming the option', function (array $options, string $message) {
    expect(fn () => ResourceOptions::parse('Invoice', $options))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'navigation as text' => [['navigation' => 'no'], 'navigation'],
    'unknown table style' => [['tableStyle' => 'wide'], 'default, tight'],
    'unknown click action' => [['clickAction' => 'open'], 'detail, edit, select, preview, ignore'],
    'zero per page' => [['perPage' => [0]], 'perPage'],
    'empty per page' => [['perPage' => []], 'perPage'],
    'per page as text' => [['perPage' => ['ten']], 'perPage'],
    'negative debounce' => [['debounce' => -1], 'debounce'],
    'blank label' => [['label' => '  '], 'label'],
    'label as list' => [['singular' => ['a']], 'singular'],
    'globalSearch as text' => [['globalSearch' => 'yes'], 'globalSearch'],
    'unknown globalSearch key' => [['globalSearch' => ['bogus' => 1]], 'bogus'],
    'zero global results' => [['globalSearch' => ['results' => 0]], 'globalSearch.results'],
]);
