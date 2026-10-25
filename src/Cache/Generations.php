<?php

declare(strict_types=1);

namespace Atannex\Retrive\Cache;

use Illuminate\Contracts\Cache\Repository;

/**
 * One counter per resource, part of every cache key. Bumping it makes all
 * of the resource's cached retrievals unreachable at once (they then expire
 * on their own), which needs no cache tags and works on every store.
 */
final class Generations
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix,
    ) {}

    public function current(string $resource): int
    {
        return (int) $this->cache->get($this->key($resource), 0);
    }

    /**
     * @param  array<int, string>  $resources
     * @return array<string, int>
     */
    public function many(array $resources): array
    {
        $generations = [];

        foreach (array_unique($resources) as $resource) {
            $generations[$resource] = $this->current($resource);
        }

        ksort($generations);

        return $generations;
    }

    public function bump(string $resource): int
    {
        $key = $this->key($resource);

        // add() is atomic where the store supports it, so two first bumps never race to 1.
        $this->cache->add($key, 0);

        return (int) $this->cache->increment($key);
    }

    private function key(string $resource): string
    {
        return "{$this->prefix}:generation:{$resource}";
    }
}
