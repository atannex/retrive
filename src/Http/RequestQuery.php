<?php

declare(strict_types=1);

namespace Atannex\Retrive\Http;

use Atannex\Retrive\Exceptions\InvalidQuery;
use Atannex\Retrive\Retriever;
use Atannex\Retrive\Support\Values;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Applies a request's query string to a retriever, within what the
 * resource allows:
 *
 *     ?filter[category]=news,sport   resource $filters
 *     ?filter[year]=2026
 *     ?sort=-published_at,title      resource $sorts ("-" = descending)
 *     ?include=tags,author.user      resource $includes
 *     ?q=flood fontem                resource $searchable
 *     ?per_page=20                   capped at config retrive.max_per_page
 *
 * Anything the resource does not allow is refused with a 400 in strict
 * mode, else ignored. Parameter names come from config retrive.request.
 */
final class RequestQuery
{
    /**
     * @param  array{filter?: string, sort?: string, include?: string, per_page?: string, search?: string, strict?: bool}  $names
     */
    public function __construct(
        private readonly array $names,
        private readonly int $maxPerPage,
    ) {}

    public function apply(Retriever $retriever, Request $request): void
    {
        $this->filters($retriever, $request);
        $this->search($retriever, $request);
        $this->sorts($retriever, $request);
        $this->includes($retriever, $request);
        $this->perPage($retriever, $request);
    }

    private function filters(Retriever $retriever, Request $request): void
    {
        $parameter = $this->name('filter');
        $filters = $request->query($parameter);

        if ($filters === null || $filters === '' || $filters === []) {
            return;
        }

        if (! is_array($filters)) {
            $this->refuse(InvalidQuery::malformed($parameter));

            return;
        }

        $allowed = $retriever->resource()->filters();

        foreach ($filters as $name => $value) {
            $name = (string) $name;

            if (! array_key_exists($name, $allowed)) {
                $this->refuse(InvalidQuery::disallowed('filter', $name, array_keys($allowed)));

                continue;
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if (is_array($value) && array_filter(array_keys($value), 'is_string') !== []) {
                $this->refuse(InvalidQuery::malformed("{$parameter}[{$name}]"));

                continue;
            }

            $target = $allowed[$name];
            $value = is_string($value) && str_contains($value, ',') ? Values::list($value, splitCommas: true) : $value;

            match (true) {
                $target instanceof Closure => $target($retriever, $value),
                $target === 'search' => $retriever->search(is_array($value) ? implode(' ', $value) : (string) $value),
                default => $retriever->whereField($target, $value),
            };
        }
    }

    private function search(Retriever $retriever, Request $request): void
    {
        $term = $request->query($this->name('search'));

        if (is_string($term) && trim($term) !== '' && $retriever->resource()->searchable() !== []) {
            $retriever->search($term);
        }
    }

    private function sorts(Retriever $retriever, Request $request): void
    {
        $parameter = $this->name('sort');
        $sort = $request->query($parameter);

        if ($sort === null || $sort === '') {
            return;
        }

        if (! is_string($sort)) {
            $this->refuse(InvalidQuery::malformed($parameter));

            return;
        }

        $allowed = $retriever->resource()->sorts();
        $applied = false;

        foreach (explode(',', $sort) as $part) {
            if (($part = trim($part)) === '') {
                continue;
            }

            $direction = str_starts_with($part, '-') ? 'desc' : 'asc';
            $name = ltrim($part, '-+');

            if (! array_key_exists($name, $allowed)) {
                $this->refuse(InvalidQuery::disallowed('sort', $name, array_keys($allowed)));

                continue;
            }

            if (! $applied) {
                // A requested sort replaces any order set in code.
                $retriever->unordered();
                $applied = true;
            }

            $retriever->tap(fn (Builder $query) => $retriever->resource()->applySort($query, $name, $direction));
        }
    }

    private function includes(Retriever $retriever, Request $request): void
    {
        $parameter = $this->name('include');
        $include = $request->query($parameter);

        if ($include === null || $include === '') {
            return;
        }

        if (! is_string($include)) {
            $this->refuse(InvalidQuery::malformed($parameter));

            return;
        }

        $allowed = $retriever->resource()->includes();
        $relations = [];

        foreach (array_filter(array_map('trim', explode(',', $include))) as $relation) {
            in_array($relation, $allowed, true)
                ? $relations[] = $relation
                : $this->refuse(InvalidQuery::disallowed('include', $relation, $allowed));
        }

        if ($relations !== []) {
            $retriever->with(array_values(array_unique($relations)));
        }
    }

    private function perPage(Retriever $retriever, Request $request): void
    {
        $parameter = $this->name('per_page');
        $perPage = $request->query($parameter);

        if ($perPage === null || $perPage === '') {
            return;
        }

        if (! is_string($perPage) || ! ctype_digit($perPage) || (int) $perPage < 1) {
            $this->refuse(InvalidQuery::malformed($parameter));

            return;
        }

        $retriever->pageSize(min((int) $perPage, $this->maxPerPage));
    }

    private function name(string $parameter): string
    {
        return (string) ($this->names[$parameter] ?? $parameter);
    }

    private function refuse(InvalidQuery $exception): void
    {
        if ($this->names['strict'] ?? true) {
            throw $exception;
        }
    }
}
