<?php

declare(strict_types=1);

namespace Atannex\Retrive\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Constrains a query to records related to the given ones, whatever the
 * relation type: belongs-to (a plain where on the foreign key), has-one/many,
 * many-to-many and morph-to-many (a whereHas), morph-to (type and id).
 *
 * Related records may be given as models, keys or slugs, mixed, one or many.
 * When the related model is a tree, its descendants match too.
 *
 * Slugs and descendants are looked up through $relatedQuery, which applies
 * the related resource's visibility rule: a hidden category's slug matches
 * nothing, and a hidden node prunes its whole branch. Models and keys given
 * directly are taken as they are.
 */
final class RelationFilter
{
    /**
     * Deepest tree walked when expanding descendants.
     */
    public const MAX_DEPTH = 32;

    /**
     * @param  Closure(Model): string  $slugColumnFor  the slug column of a related model
     * @param  (Closure(Model): Builder)|null  $relatedQuery  a query on the related records (default: all of them)
     */
    public function __construct(
        private readonly Closure $slugColumnFor,
        private readonly ?Closure $relatedQuery = null,
    ) {}

    public static function relation(Model $model, string $name): ?Relation
    {
        if (! method_exists($model, $name)) {
            return null;
        }

        try {
            $relation = $model->{$name}();
        } catch (\Throwable) {
            return null;
        }

        return $relation instanceof Relation ? $relation : null;
    }

    public function apply(Builder $query, string $name, Relation $relation, mixed $value, ?string $parentColumn = null): void
    {
        $values = Values::list($value, splitCommas: true);

        if ($value === null || $values === []) {
            $relation instanceof BelongsTo
                ? $query->whereNull($relation->getQualifiedForeignKeyName())
                : $query->doesntHave($name);

            return;
        }

        if ($relation instanceof MorphTo) {
            $this->morphedTo($query, $name, $values);

            return;
        }

        $related = $relation->getRelated();
        $keys = $this->keys($related, $values);

        if ($parentColumn !== null && $keys !== []) {
            $keys = $this->withDescendants($related, $keys, $parentColumn);
        }

        if ($keys === []) {
            $query->whereRaw('0 = 1');

            return;
        }

        if ($relation instanceof BelongsTo) {
            $query->whereIn($relation->getQualifiedForeignKeyName(), $keys);

            return;
        }

        $query->whereHas($name, fn (Builder $query) => $query->whereIn($related->getQualifiedKeyName(), $keys));
    }

    /**
     * Keys of the related records named by models, keys or slugs.
     *
     * @param  array<int, mixed>  $values
     * @return array<int, int|string>
     */
    public function keys(Model $related, array $values): array
    {
        $keys = [];
        $slugs = [];

        foreach ($values as $value) {
            if ($value instanceof Model) {
                $keys[] = $value->getKey();
            } elseif (Values::isKey($value, $related)) {
                $keys[] = is_string($value) && $related->getKeyType() === 'int' ? (int) $value : $value;
            } else {
                $slugs[] = (string) $value;
            }
        }

        if ($slugs !== []) {
            $query = $this->query($related);
            self::whereSlug($query, ($this->slugColumnFor)($related), $slugs);

            array_push($keys, ...$query->pluck($related->getQualifiedKeyName())->all());
        }

        return array_values(array_unique($keys, SORT_REGULAR));
    }

    /**
     * Constrain a query to the given slug(s); "relation.column" looks through a relation.
     *
     * @param  string|array<int, string>  $slugs
     */
    public static function whereSlug(Builder $query, string $column, string|array $slugs): void
    {
        $slugs = (array) $slugs;

        if (str_contains($column, '.')) {
            $relation = substr($column, 0, strrpos($column, '.'));
            $column = substr($column, strrpos($column, '.') + 1);

            $query->whereHas($relation, fn (Builder $related) => $related->whereIn($related->qualifyColumn($column), $slugs));

            return;
        }

        $query->whereIn($query->qualifyColumn($column), $slugs);
    }

    /**
     * The given keys plus every descendant's, walking the tree level by level.
     *
     * @param  array<int, int|string>  $keys
     * @return array<int, int|string>
     */
    public function withDescendants(Model $related, array $keys, string $parentColumn): array
    {
        $all = array_fill_keys(array_map('strval', $keys), true);
        $result = $keys;
        $frontier = $keys;

        for ($depth = 0; $frontier !== [] && $depth < self::MAX_DEPTH; $depth++) {
            $children = $this->query($related)
                ->whereIn($related->qualifyColumn($parentColumn), $frontier)
                ->pluck($related->getQualifiedKeyName())
                ->all();

            $frontier = [];

            foreach ($children as $child) {
                if (! isset($all[(string) $child])) {
                    $all[(string) $child] = true;
                    $frontier[] = $child;
                    $result[] = $child;
                }
            }
        }

        return $result;
    }

    private function query(Model $related): Builder
    {
        return $this->relatedQuery !== null ? ($this->relatedQuery)($related) : $related->newQuery();
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function morphedTo(Builder $query, string $name, array $values): void
    {
        $models = array_values(array_filter($values, fn ($value) => $value instanceof Model));

        if ($models === []) {
            throw new \InvalidArgumentException("Filtering by the morph-to relation [{$name}] needs model instances.");
        }

        $query->where(function (Builder $query) use ($name, $models) {
            foreach ($models as $model) {
                $query->orWhereMorphedTo($name, $model);
            }
        });
    }
}
