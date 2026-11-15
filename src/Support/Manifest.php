<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintNovaResources\Support;

/**
 * Remembers what blueprint_nova_resources did, so `blueprint:erase` can undo it safely.
 *
 *  resources: resource file => { class, created, seed }
 *      created = we created the file (erase may delete it)
 *      seed    = hash of the `fields()` method exactly as first written; while it is unchanged, `fields()` is
 *                not treated as developer code
 *
 * Like `.blueprint`, it describes the latest build only.
 */
final class Manifest
{
    /** @param  array<string, array{class:string, created:bool, seed:?string}>  $resources */
    public function __construct(private array $resources = []) {}

    public static function fromJson(?string $json): self
    {
        if ($json === null || trim($json) === '') {
            return new self();
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return new self();
        }

        return new self(is_array($decoded['resources'] ?? null) ? $decoded['resources'] : []);
    }

    public function recordResource(string $path, string $class, bool $created, ?string $seed): void
    {
        $this->resources[$path] = ['class' => $class, 'created' => $created, 'seed' => $seed];
    }

    /** @return array{class:string, created:bool, seed:?string}|null */
    public function resource(string $path): ?array
    {
        return $this->resources[$path] ?? null;
    }

    /** @return array<string, array{class:string, created:bool, seed:?string}> */
    public function resources(): array
    {
        return $this->resources;
    }

    public function isEmpty(): bool
    {
        return $this->resources === [];
    }

    public function toJson(): string
    {
        return json_encode(['resources' => $this->resources], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
