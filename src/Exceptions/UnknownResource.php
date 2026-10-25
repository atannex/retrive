<?php

declare(strict_types=1);

namespace Atannex\Retrive\Exceptions;

use InvalidArgumentException;

final class UnknownResource extends InvalidArgumentException implements RetriveException
{
    /**
     * @param  array<int, string>  $known
     */
    public static function named(string $name, array $known): self
    {
        $list = $known === [] ? 'none are registered' : 'registered: '.implode(', ', $known);

        return new self("No retrieval resource is named [{$name}] ({$list}). Add it to config/retrive.php.");
    }
}
