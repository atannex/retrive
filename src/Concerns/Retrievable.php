<?php

declare(strict_types=1);

namespace Atannex\Retrive\Concerns;

use Atannex\Retrive\RetrievalManager;
use Atannex\Retrive\Retriever;

/**
 * Model::retrieve() starts a retrieval of the model's resource:
 *
 *     Post::retrieve()->byCategory('news')->latest()->get(5);
 */
trait Retrievable
{
    public static function retrieve(): Retriever
    {
        return app(RetrievalManager::class)->for(static::class);
    }
}
