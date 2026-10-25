<?php

declare(strict_types=1);

namespace Atannex\Retrive\Facades;

use Atannex\Retrive\Resource;
use Atannex\Retrive\RetrievalManager;
use Atannex\Retrive\Retriever;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Retriever for(string|\Illuminate\Database\Eloquent\Model|Resource $name)
 * @method static Resource register(string|Resource $resource, ?string $key = null)
 * @method static RetrievalManager registerMany(array $entries)
 * @method static Resource resource(string|\Illuminate\Database\Eloquent\Model|Resource $name)
 * @method static bool has(string $name)
 * @method static array<string, Resource> resources()
 * @method static void flush(?string $name = null)
 * @method static \Closure visible(string|\Illuminate\Database\Eloquent\Model|Resource $name, ?\Closure $constraint = null)
 * @method static \Illuminate\Database\Eloquent\Builder visibleQuery(string|\Illuminate\Database\Eloquent\Model $model)
 * @method static mixed callFinder(\Atannex\Retrive\Finders\Finder $finder, array $arguments)
 *
 * Plus a retriever per resource key (Retrive::posts()) and magic finders:
 * Retrive::getPostBySlug($slug), Retrive::getPostsByCategory($category, 10)…
 *
 * @see RetrievalManager
 */
final class Retrive extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RetrievalManager::class;
    }
}
