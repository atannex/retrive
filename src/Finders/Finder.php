<?php

declare(strict_types=1);

namespace Atannex\Retrive\Finders;

use Atannex\Retrive\Resource;

/**
 * A parsed magic finder name: getPostsByCategoryAndRegion =>
 * operation "get", resource posts, plural, fields [category, region].
 */
final class Finder
{
    /**
     * @param  array<int, string>  $fields
     */
    public function __construct(
        public readonly string $method,
        public readonly string $operation,
        public readonly Resource $resource,
        public readonly bool $plural,
        public readonly array $fields,
        public readonly bool $orFail,
    ) {}

    /**
     * Whether the finder returns one record (as opposed to a list, a page, a count…).
     */
    public function returnsOne(): bool
    {
        return ! $this->plural && in_array($this->operation, ['get', 'find', 'first', 'latest', 'oldest', 'random'], true);
    }
}
