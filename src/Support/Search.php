<?php

declare(strict_types=1);

namespace Atannex\Retrive\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Portable LIKE search (MySQL, MariaDB, SQLite, PostgreSQL, SQL Server).
 *
 * The term is split into words, "quoted phrases" staying whole. Every word
 * must match at least one column; "relation.column" columns look through
 * relations. Wildcards in the term are escaped, so "50%" means 50%.
 */
final class Search
{
    /**
     * Words beyond this are ignored, to bound the query.
     */
    public const MAX_WORDS = 10;

    /**
     * @param  array<int, string>  $columns
     */
    public static function apply(Builder $query, string $term, array $columns): void
    {
        $words = self::words($term);

        if ($words === [] || $columns === []) {
            return;
        }

        foreach ($words as $word) {
            $query->where(function (Builder $query) use ($word, $columns) {
                foreach ($columns as $column) {
                    self::orMatch($query, $column, $word);
                }
            });
        }
    }

    /**
     * @return array<int, string>
     */
    public static function words(string $term): array
    {
        preg_match_all('/"([^"]+)"|(\S+)/u', $term, $matches, PREG_SET_ORDER);

        $words = [];

        foreach ($matches as $match) {
            $word = trim($match[1] !== '' ? $match[1] : ($match[2] ?? ''));

            if ($word !== '' && ! in_array($word, $words, true)) {
                $words[] = $word;
            }
        }

        return array_slice($words, 0, self::MAX_WORDS);
    }

    public static function escape(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private static function orMatch(Builder $query, string $column, string $word): void
    {
        if (str_contains($column, '.')) {
            $relation = substr($column, 0, strrpos($column, '.'));
            $column = substr($column, strrpos($column, '.') + 1);

            $query->orWhereHas($relation, fn (Builder $related) => self::like($related, $column, $word));

            return;
        }

        $query->orWhere(fn (Builder $query) => self::like($query, $column, $word));
    }

    private static function like(Builder $query, string $column, string $word): void
    {
        $connection = $query->getConnection();
        $operator = $connection->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $wrapped = $query->getQuery()->getGrammar()->wrap($query->qualifyColumn($column));

        $query->whereRaw("{$wrapped} {$operator} ? ESCAPE '!'", ['%'.self::escape($word).'%']);
    }
}
