<?php

declare(strict_types=1);

namespace Atannex\Retrive\Exceptions;

use BadMethodCallException;

final class UnknownFinder extends BadMethodCallException implements RetriveException
{
    public static function method(string $method): self
    {
        return new self(
            "[{$method}] is not a finder. Finders read {operation}{Resource}[By{Field}[And{Field}…]][OrFail], "
            .'e.g. getPostBySlug, getPostsByCategory, countPostsByTag, paginatePostsByAuthor.'
        );
    }

    public static function arguments(string $method, int $expected, int $given): self
    {
        return new self("[{$method}] needs {$expected} value(s), one per field; {$given} given.");
    }
}
