<?php

declare(strict_types=1);

namespace Atannex\Retrive\Exceptions;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * A request asked for a filter, sort or include the resource does not
 * allow. Renders as 400 Bad Request.
 */
final class InvalidQuery extends BadRequestHttpException implements RetriveException
{
    /**
     * @param  array<int, string>  $allowed
     */
    public static function disallowed(string $kind, string $name, array $allowed): self
    {
        $list = $allowed === [] ? 'none are allowed' : 'allowed: '.implode(', ', $allowed);

        return new self("The {$kind} [{$name}] is not allowed ({$list}).");
    }

    public static function malformed(string $parameter): self
    {
        return new self("The [{$parameter}] parameter is malformed.");
    }
}
