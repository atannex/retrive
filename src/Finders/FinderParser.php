<?php

declare(strict_types=1);

namespace Atannex\Retrive\Finders;

use Atannex\Retrive\Resource;
use Atannex\Retrive\Support\Values;
use Closure;

/**
 * Reads magic finder names:
 *
 *     {operation}{Resource}[By{Field}[And{Field}…]][OrFail]
 *
 *     getPostBySlug            one post
 *     getPostById              one post
 *     getPostsByCategory       posts in a category (and its subcategories)
 *     getPostBycategory        the same: field names ignore case
 *     getPostsByCategoryAndRegion
 *     latestPostsByTag, oldestDocuments, randomPosts
 *     countPostsByAuthor, existsPostBySlug, paginatePostsByRegion
 *     getPostBySlugOrFail      404 when missing
 *
 * The resource is read as the singular or plural of its key: singular
 * returns one record, plural a collection.
 */
final class FinderParser
{
    public const OPERATIONS = ['get', 'find', 'first', 'latest', 'oldest', 'random', 'count', 'exists', 'paginate'];

    /**
     * @param  Closure(string): ?array{0: resource, 1: bool}  $resolve  token => [resource, plural]
     */
    public function __construct(
        private readonly Closure $resolve,
    ) {}

    public function parse(string $method): ?Finder
    {
        $pattern = '/^('.implode('|', self::OPERATIONS).')([A-Z].*)$/';

        if (! preg_match($pattern, $method, $matches)) {
            return null;
        }

        [, $operation, $rest] = $matches;
        $orFail = false;

        if (str_ends_with($rest, 'OrFail') && $rest !== 'OrFail') {
            $rest = substr($rest, 0, -strlen('OrFail'));
            $orFail = true;
        }

        foreach ($this->splits($rest) as [$token, $fields]) {
            if ($fields === '' && $token !== $rest) {
                continue;
            }

            $resolved = ($this->resolve)($token);

            if ($resolved === null) {
                continue;
            }

            [$resource, $plural] = $resolved;

            return new Finder($method, $operation, $resource, $plural, $fields === '' ? [] : Values::fields($fields), $orFail);
        }

        return null;
    }

    /**
     * Every way to read "PostsByCategory": the whole name, then each "By"
     * as the boundary, so a resource whose name contains "By" still parses.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function splits(string $rest): array
    {
        $splits = [[$rest, '']];
        $offset = 0;

        while (($position = strpos($rest, 'By', $offset)) !== false) {
            if ($position > 0) {
                $splits[] = [substr($rest, 0, $position), substr($rest, $position + 2)];
            }

            $offset = $position + 2;
        }

        return $splits;
    }
}
