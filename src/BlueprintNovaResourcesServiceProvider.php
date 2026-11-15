<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintNovaResources;

use Blueprint\Blueprint;
use Dakotajune\BlueprintNovaResources\Fields\FieldMapperRegistry;
use Dakotajune\BlueprintNovaResources\Generators\NovaResourceGenerator;
use Dakotajune\BlueprintNovaResources\Lexers\NovaResourceLexer;
use Dakotajune\BlueprintNovaResources\Listeners\CleanUpOnErase;
use Dakotajune\BlueprintNovaResources\Support\NoticeBag;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\ServiceProvider;

/**
 * Deliberately NOT a DeferrableProvider: Blueprint's own provider already defers Blueprint::class, and two
 * deferred providers cannot both own one service. We also need boot() to run so the erase listener is
 * registered before `blueprint:erase` starts.
 *
 * Other packages add field mappers from their own provider:
 *
 *     $this->app->make(\Dakotajune\BlueprintNovaResources\Fields\FieldMapperRegistry::class)->register(new MyMapper(), priority: 10);
 */
final class BlueprintNovaResourcesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/blueprint_nova_resources.php', 'blueprint_nova_resources');

        $this->app->singleton(FieldMapperRegistry::class);
        $this->app->singleton(NoticeBag::class);

        // extend() runs when Blueprint is resolved, whatever order the providers loaded in.
        $this->app->extend(Blueprint::class, function (Blueprint $blueprint, $app): Blueprint {
            $blueprint->registerLexer($app->make(NovaResourceLexer::class));
            $blueprint->registerGenerator($app->make(NovaResourceGenerator::class));

            return $blueprint;
        });
    }

    public function boot(): void
    {
        $events = $this->app['events'];

        // Resolve the listener only when erase really starts, not for every artisan command.
        $events->listen(CommandStarting::class, function (CommandStarting $event): void {
            if ($event->command === 'blueprint:erase') {
                $this->app->make(CleanUpOnErase::class)->handle($event);
            }
        });

        $events->listen(CommandFinished::class, function (CommandFinished $event): void {
            if (! in_array($event->command, ['blueprint:build', 'blueprint:erase'], true)) {
                return;
            }

            foreach ($this->app->make(NoticeBag::class)->pull() as $notice) {
                $event->output->writeln("<comment>blueprint_nova_resources:</comment> {$notice}");
            }
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/blueprint_nova_resources.php' => config_path('blueprint_nova_resources.php'),
            ], 'blueprint-nova-resources-config');

            $this->publishes([
                __DIR__ . '/../stubs/nova-resource.stub' => base_path('stubs/blueprint/nova-resource.stub'),
            ], 'blueprint-nova-resources-stubs');
        }
    }
}
