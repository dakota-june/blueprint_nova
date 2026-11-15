<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintNovaResources\Tests;

use Blueprint\BlueprintServiceProvider;
use Dakotajune\BlueprintNovaResources\BlueprintNovaResourcesServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [BlueprintServiceProvider::class, BlueprintNovaResourcesServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        (new Filesystem())->deleteDirectory(base_path('app/Nova'));
        @unlink(base_path('.blueprint_nova_resources'));
        @unlink(base_path('.blueprint'));
    }

    /** Runs the real Blueprint lexers and generators over a draft (already-parsed array form). */
    protected function build(array $draft): array
    {
        $blueprint = $this->app->make(\Blueprint\Blueprint::class);
        $tree = $blueprint->analyze($draft);

        $output = [];
        foreach ($blueprint->generate($tree) as $key => $paths) {
            $output[$key] = $paths;
        }

        return $output;
    }

    protected function draft(): array
    {
        return require __DIR__ . '/fixtures/draft.php';
    }

    protected function novaPath(string $name): string
    {
        return base_path("app/Nova/{$name}.php");
    }
}
