<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintNovaResources\Listeners;

use Dakotajune\BlueprintNovaResources\Support\BlueprintFile;
use Dakotajune\BlueprintNovaResources\Support\ManifestStore;
use Dakotajune\BlueprintNovaResources\Support\NoticeBag;
use Dakotajune\BlueprintNovaResources\Support\ResourceClassEditor;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Filesystem\Filesystem;

/**
 * `blueprint:erase` deletes every file listed under `created` in `.blueprint`. Left alone that would destroy
 * the code a developer added to a generated resource. So, just before it starts, we:
 *
 *  1. strip the `@generated blueprint_nova_resources` members from each resource we touched;
 *  2. if the resource still holds developer content (anything besides a `fields()` that is exactly what we first
 *     wrote), or we did not create the file, take it off erase's delete list by editing `.blueprint`
 *     (backed up first), so erase leaves it alone.
 *
 * Resources with nothing of the developer's left stay on the list for erase to delete as normal.
 */
final class CleanUpOnErase
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly ResourceClassEditor $editor,
        private readonly ManifestStore $manifests,
        private readonly BlueprintFile $blueprintFile,
        private readonly NoticeBag $notices,
    ) {}

    public function handle(CommandStarting $event): void
    {
        if ($event->command !== 'blueprint:erase') {
            return;
        }

        $manifest = $this->manifests->load();

        if ($manifest->isEmpty()) {
            return;
        }

        $protect = [];

        foreach ($manifest->resources() as $path => $meta) {
            $absolute = base_path($path);

            if (! $this->files->exists($absolute)) {
                continue;
            }

            $original = $this->files->get($absolute);
            $stripped = $this->editor->stripGenerated($original, $meta['class']);

            if ($stripped !== $original) {
                $this->files->put($absolute, $stripped);
            }

            if (! $meta['created'] || $this->editor->hasDeveloperContent($stripped, $meta['class'], $meta['seed'] ?? null)) {
                $protect[] = $path;
                $this->notices->add("{$path} still contains your code, so it was kept; only the generated members were removed.");
            }
        }

        $backup = $this->blueprintFile->protect($protect);

        if ($backup !== null) {
            $this->notices->add('Edited .blueprint so erase skips the files above. The original is saved as ' . basename($backup) . '.');
        }

        $this->manifests->delete();
    }
}
