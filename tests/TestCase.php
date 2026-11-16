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

        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();

        parent::tearDown();
    }

    private function clean(): void
    {
        (new Filesystem())->deleteDirectory(base_path('app/Nova'));
        @unlink(base_path('.blueprint_nova_resources'));
        @unlink(base_path('.blueprint'));
        @unlink(base_path('app/Providers/NovaServiceProvider.php'));

        // The Testbench skeleton outlives a run, so backups written by the erase test must not pile up between runs.
        foreach (glob(base_path('.blueprint.bak-*')) ?: [] as $backup) {
            @unlink($backup);
        }

        $this->app->make(\Dakotajune\BlueprintNovaResources\Support\NoticeBag::class)->pull();
    }

    /** @return array<int, string> */
    protected function notices(): array
    {
        return $this->app->make(\Dakotajune\BlueprintNovaResources\Support\NoticeBag::class)->pull();
    }

    /** Runs the real Blueprint lexers, and our generator only, over a draft (already-parsed array form). */
    protected function build(array $draft): array
    {
        $blueprint = $this->app->make(\Blueprint\Blueprint::class);
        $tree = $blueprint->analyze($draft);

        // Only our generator (type "nova"): Blueprint's own generators would write migrations, models and so on
        // into directories the Testbench skeleton does not have.
        return $blueprint->generate($tree, ['nova']);
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
