<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/** Resource paths recorded in the manifest, or null when there is no manifest file. */
function manifestPaths(): ?array
{
    $file = base_path('.blueprint_nova_resources');

    return file_exists($file) ? array_keys(json_decode(file_get_contents($file), true)['resources'] ?? []) : null;
}

it('records every built resource in the manifest', function () {
    $this->build($this->draft());

    expect(manifestPaths())->toBe(['app/Nova/Invoice.php', 'app/Nova/Payment.php', 'app/Nova/User.php']);
});

it('clears the manifest when the nova section is removed, and leaves the resources alone', function () {
    $this->build($this->draft());

    $draft = $this->draft();
    unset($draft['nova']);
    $this->build($draft);

    expect(manifestPaths())->toBeNull()
        ->and(file_exists($this->novaPath('Invoice')))->toBeTrue();
});

it('clears the manifest when nova is switched off', function () {
    $this->build($this->draft());

    $draft = $this->draft();
    $draft['nova'] = false;
    $this->build($draft);

    expect(manifestPaths())->toBeNull();
});

it('drops a resource from the manifest when it leaves the draft', function () {
    $this->build($this->draft());

    $draft = $this->draft();
    $draft['nova'] = ['Payment' => null];
    $this->build($draft);

    expect(manifestPaths())->toBe(['app/Nova/Payment.php']);
});

it('does not create a manifest for a draft that never had nova resources', function () {
    $draft = $this->draft();
    unset($draft['nova']);
    $this->build($draft);

    expect(manifestPaths())->toBeNull();
});

it('does not touch resources on erase after the nova section was removed', function () {
    $this->build($this->draft());

    $draft = $this->draft();
    unset($draft['nova']);
    $this->build($draft);

    event(new CommandStarting('blueprint:erase', new ArrayInput([]), new NullOutput()));

    expect(file_get_contents($this->novaPath('Invoice')))->toContain('@generated blueprint_nova_resources');
});
