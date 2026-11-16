<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

it('protects edited resources from blueprint:erase and leaves untouched ones deletable', function () {
    $out = $this->build($this->draft());
    file_put_contents(base_path('.blueprint'), $this->app->make(\Blueprint\Blueprint::class)->dump(['created' => array_column($out['created'], 1), 'updated' => []]));

    $payment = file_get_contents($this->novaPath('Payment'));
    file_put_contents($this->novaPath('Payment'), str_replace(
        '...$this->blueprintFields($request),',
        "...\$this->blueprintFields(\$request),\n            Text::make('Mine', 'mine'),",
        $payment,
    ));

    event(new CommandStarting('blueprint:erase', new ArrayInput([]), new NullOutput()));

    $created = $this->app->make(\Blueprint\Blueprint::class)->parse(file_get_contents(base_path('.blueprint')), false)['created'];

    expect($created)->not->toContain('app/Nova/Payment.php')
        ->toContain('app/Nova/Invoice.php', 'app/Nova/User.php');
    expect(file_get_contents($this->novaPath('Payment')))
        ->toContain("Text::make('Mine', 'mine')")
        ->not->toContain('@generated');
    expect(glob(base_path('.blueprint.bak-*')))->toHaveCount(1);
    expect(file_exists(base_path('.blueprint_nova_resources')))->toBeFalse();
});
