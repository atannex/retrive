<?php

declare(strict_types=1);

namespace Atannex\Retrive;

use Atannex\Retrive\Contracts\Criterion;
use Atannex\Retrive\Events\Retrieved;
use Atannex\Retrive\Exceptions\InvalidResource;
use Atannex\Retrive\Exceptions\UnknownFinder;
use Atannex\Retrive\Support\RelationFilter;
use Atannex\Retrive\Support\Search;
use Atannex\Retrive\Support\Values;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator as SimplePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Pagination\Paginator as PageResolver;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;

/**
 * A fluent retrieval of one resource's records.
 *
 *     Retrive::posts()->byCategory('news')->with('tags')->latest()->get(10);
 *     Retrive::posts()->search('flood fontem')->inYear(2026)->paginate();
 *     Retrive::posts()->findBySlugOrFail($slug);
 *     Retrive::posts()->relatedTo($post, via: 'tags', sameAs: 'category_id')->get(6);
 *
 * Chainable methods constrain the query and return the retriever; terminal
 * methods (get, first, paginate, count…) run it. The resource's visibility
 * rule is applied when the query runs, so withHidden() can come anywhere in
 * the chain. A retriever can run several terminals: each works on a copy.
 */
class Retriever
{
    use Conditionable;
    use Macroable {
        __call as macroCall;
    }

    protected Builder $query;

    protected bool $visibility = true;

    /**
     * Whether relation filters on a tree include descendants (null: the
     * resource's $hierarchies decide).
     */
    protected ?bool $descendants = null;

    /**
     * Cache lifetime in seconds; null: the resource's TTL; false: never.
     */
    protected int|false|null $ttl = null;

    /**
     * Page size paginate() uses when none is passed (set by pageSize() or a request's ?per_page=).
     */
    protected ?int $pageSize = null;

    public function __construct(
        protected readonly Resource $resource,
        protected readonly RetrievalManager $manager,
    ) {
        $this->query = $resource->newQuery();

        if ($with = $resource->with()) {
            $this->query->with($with);
        }

        if ($withCount = $resource->withCount()) {
            $this->query->withCount($withCount);
        }
    }

    public function resource(): Resource
    {
        return $this->resource;
    }

    /*
    |--------------------------------------------------------------------------
    | Lookups (terminal)
    |--------------------------------------------------------------------------
    */

    public function find(mixed $id): ?Model
    {
        if ($id === null || $id === '') {
            return null;
        }

        return $this->run('find', [$id], fn (Builder $query) => $query->whereKey($id)->first());
    }

    public function findOrFail(mixed $id): Model
    {
        return $this->find($id) ?? throw $this->notFound([$id]);
    }

    /**
     * Records with the given keys, in the order given; unknown keys are skipped.
     *
     * @param  iterable<int, int|string>  $ids
     */
    public function findMany(iterable $ids): Collection
    {
        $ids = array_values(array_unique(Values::list($ids, splitCommas: true), SORT_REGULAR));

        if ($ids === []) {
            return $this->newCollection();
        }

        $found = $this->run('findMany', [$ids], fn (Builder $query) => $query->whereKey($ids)->get())
            ->keyBy(fn (Model $model) => (string) $model->getKey());

        return $this->newCollection(
            array_values(array_filter(array_map(fn ($id) => $found->get((string) $id), $ids)))
        );
    }

    public function findBySlug(?string $slug): ?Model
    {
        if ($slug === null || ($slug = trim($slug)) === '') {
            return null;
        }

        return $this->run('findBySlug', [$slug], function (Builder $query) use ($slug) {
            RelationFilter::whereSlug($query, $this->resource->slugColumn(), $slug);

            return $query->first();
        });
    }

    public function findBySlugOrFail(?string $slug): Model
    {
        return $this->findBySlug($slug) ?? throw $this->notFound([$slug]);
    }

    /**
     * The first record whose field matches, the field resolved as finders do
     * (alias, id, slug, relation or column).
     */
    public function findBy(string $field, mixed $value): ?Model
    {
        return (clone $this)->whereField($field, $value)->first();
    }

    public function findByOrFail(string $field, mixed $value): Model
    {
        return $this->findBy($field, $value) ?? throw $this->notFound([$value]);
    }

    /*
    |--------------------------------------------------------------------------
    | Filters (chainable)
    |--------------------------------------------------------------------------
    */

    /**
     * Constrain by a field, resolved in this order:
     *  1. a resource alias (column, relation or closure)
     *  2. "id" / "key": the primary key
     *  3. "slug": the resource's slug column
     *  4. a relation of the model (singular or plural name): related records
     *     given as models, keys or slugs, descendants included for trees
     *  5. otherwise the snake_case column; a list means IN, null means IS NULL
     */
    public function whereField(string $field, mixed $value): static
    {
        $field = Str::camel($field);
        $aliases = $this->resource->aliases();

        if (isset($aliases[$field])) {
            $target = $aliases[$field];

            if ($target instanceof Closure) {
                $target($this, $value);

                return $this;
            }

            $field = $target;
        }

        if (in_array(strtolower($field), ['id', 'key'], true)) {
            $values = Values::list($value, splitCommas: true);
            $values === [] ? $this->query->whereRaw('0 = 1') : $this->query->whereKey($values);

            return $this;
        }

        if (strtolower($field) === 'slug') {
            RelationFilter::whereSlug($this->query, $this->resource->slugColumn(), array_map('strval', Values::list($value)));

            return $this;
        }

        if ($relation = $this->relationNamed($field)) {
            return $this->belongingTo($relation, $value);
        }

        if (str_contains($field, '.')) {
            RelationFilter::whereSlug($this->query, $field, array_map('strval', Values::list($value)));

            return $this;
        }

        $column = $this->query->qualifyColumn(Str::snake($field));

        if ($value === null) {
            $this->query->whereNull($column);
        } elseif (is_iterable($value)) {
            $this->query->whereIn($column, Values::list($value));
        } else {
            $this->query->where($column, $value instanceof Model ? $value->getKey() : $value);
        }

        return $this;
    }

    /**
     * Records related to the given ones (models, keys or slugs) through a relation.
     */
    public function belongingTo(string $relation, mixed $value, ?bool $descendants = null): static
    {
        $instance = RelationFilter::relation($this->query->getModel(), $relation)
            ?? throw InvalidResource::unknownField($this->resource->key(), $relation);

        // Trees in $hierarchies include descendants unless switched off; any
        // other relation only when asked, assuming a parent_id column.
        $wanted = $descendants ?? $this->descendants;
        $parentColumn = $wanted === false
            ? null
            : ($this->resource->hierarchies()[$relation] ?? ($wanted === true ? 'parent_id' : null));

        $this->relationFilter()->apply($this->query, $relation, $instance, $value, $parentColumn);

        return $this;
    }

    /**
     * Relation filters on trees match the given node only, not its descendants.
     */
    public function withoutDescendants(): static
    {
        $this->descendants = false;

        return $this;
    }

    public function withDescendants(): static
    {
        $this->descendants = true;

        return $this;
    }

    /**
     * Every word must match one of the columns (the resource's searchable
     * columns by default). A blank term changes nothing.
     *
     * A model with its own search scope (atannex/ecosystem's Searchable)
     * is searched through it, so ranking, synonyms and parsed queries work:
     * ->search($parsed, $options). Passing columns forces the plain search.
     *
     * @param  array<int, string>|null  $columns
     */
    public function search(mixed $term, mixed $columns = null): static
    {
        if ($term === null || (is_string($term) && trim($term) === '')) {
            return $this;
        }

        if (! is_array($columns) && $this->query->hasNamedScope('search')) {
            $columns === null ? $this->query->search($term) : $this->query->search($term, $columns);

            return $this;
        }

        if (! is_string($term)) {
            throw new \InvalidArgumentException('Only a model with a search scope takes a parsed query; ['.$this->resource->key().'] has none.');
        }

        $columns ??= $this->resource->searchable();

        if ($columns === []) {
            throw InvalidResource::notSearchable($this->resource->key());
        }

        Search::apply($this->query, $term, $columns);

        return $this;
    }

    /**
     * Records with at least one visible related record: the related
     * resource's visibility rule is applied inside the whereHas.
     *
     *     Retrive::tags()->whereHasVisible('posts', fn ($q) => $q->where('region_id', 3));
     *
     * @param  (Closure(Builder): mixed)|null  $constraint
     */
    public function whereHasVisible(string $relation, ?Closure $constraint = null, string $operator = '>=', int $count = 1): static
    {
        $this->query->whereHas($relation, $this->visibleCallback($relation, $constraint), $operator, $count);

        return $this;
    }

    /**
     * Records with no visible related record.
     *
     * @param  (Closure(Builder): mixed)|null  $constraint
     */
    public function whereDoesntHaveVisible(string $relation, ?Closure $constraint = null): static
    {
        $this->query->whereDoesntHave($relation, $this->visibleCallback($relation, $constraint));

        return $this;
    }

    /**
     * Count visible related records, as withCount() does:
     * ->withCountVisible('posts'), ->withCountVisible(['posts as usage_count' => fn ($q) => …]).
     *
     * @param  string|array<int|string, string|Closure>  $relations
     */
    public function withCountVisible(string|array $relations): static
    {
        $this->query->withCount($this->visibleRelations($relations));

        return $this;
    }

    /**
     * Eager-load only visible related records, as with() does.
     *
     * @param  string|array<int|string, string|Closure>  $relations
     */
    public function withVisible(string|array $relations): static
    {
        $this->query->with($this->visibleRelations($relations));

        return $this;
    }

    /**
     * Leave out the given records (models or keys).
     */
    public function except(mixed $records): static
    {
        $keys = array_map(fn ($record) => $record instanceof Model ? $record->getKey() : $record, Values::list($records));

        if ($keys !== []) {
            $this->query->whereKeyNot($keys);
        }

        return $this;
    }

    /**
     * Apply a model scope by name: ->scope('breaking'), ->scope('flagged', 'approved').
     */
    public function scope(string $name, mixed ...$parameters): static
    {
        $this->query->{$name}(...$parameters);

        return $this;
    }

    /**
     * Apply reusable criteria: Criterion objects or closures receiving the retriever.
     */
    public function apply(Criterion|Closure ...$criteria): static
    {
        foreach ($criteria as $criterion) {
            $criterion instanceof Criterion ? $criterion->apply($this) : $criterion($this);
        }

        return $this;
    }

    /**
     * Work on the Eloquent builder directly (joins, raw clauses…).
     *
     * @param  Closure(Builder): mixed  $callback
     */
    public function tap(Closure $callback): static
    {
        $callback($this->query);

        return $this;
    }

    /**
     * Records sharing the most `via` relations (tags) with the given one,
     * optionally only those with the same `sameAs` column values (category).
     * Records sharing none still follow, newest first.
     *
     * @param  string|array<int, string>  $sameAs
     */
    public function relatedTo(Model $model, string $via = 'tags', string|array $sameAs = [], bool $requireShared = true): static
    {
        $relation = RelationFilter::relation($this->query->getModel(), $via)
            ?? throw InvalidResource::unknownField($this->resource->key(), $via);

        $relatedKey = $relation->getRelated()->getQualifiedKeyName();
        $ids = $model->relationLoaded($via)
            ? $model->getRelation($via)->modelKeys()
            : $model->{$via}()->pluck($relatedKey)->all();

        $this->except($model);

        foreach ((array) $sameAs as $column) {
            $this->query->where($this->query->qualifyColumn($column), $model->getAttribute($column));
        }

        if ($ids !== []) {
            $constraint = fn (Builder $query) => $query->whereIn($relatedKey, $ids);

            if ($requireShared) {
                $this->query->whereHas($via, $constraint);
            }

            $this->query->withCount(["{$via} as shared_{$via}_count" => $constraint])
                ->orderByDesc("shared_{$via}_count");
        }

        return $this->latest();
    }

    /**
     * Include records the resource's visibility rule hides (drafts, unpublished…).
     */
    public function withHidden(): static
    {
        $this->visibility = false;

        return $this;
    }

    public function withTrashed(): static
    {
        $this->query->withTrashed();

        return $this;
    }

    public function onlyTrashed(): static
    {
        $this->query->onlyTrashed();

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Dates (chainable, on the resource's date column)
    |--------------------------------------------------------------------------
    */

    public function since(DateTimeInterface|string $moment): static
    {
        $this->query->where($this->dateColumn(), '>=', $this->moment($moment));

        return $this;
    }

    public function until(DateTimeInterface|string $moment): static
    {
        $this->query->where($this->dateColumn(), '<=', $this->moment($moment));

        return $this;
    }

    public function between(DateTimeInterface|string $from, DateTimeInterface|string $to): static
    {
        $this->query->whereBetween($this->dateColumn(), [$this->moment($from), $this->moment($to)]);

        return $this;
    }

    /**
     * Records dated within the last $days days.
     */
    public function recent(int $days = 7): static
    {
        return $this->since(CarbonImmutable::now()->subDays($days));
    }

    public function inYear(int $year): static
    {
        $start = CarbonImmutable::create($year)->startOfYear();

        return $this->between($start, $start->endOfYear());
    }

    public function inMonth(int $year, int $month): static
    {
        $start = CarbonImmutable::create($year, $month)->startOfMonth();

        return $this->between($start, $start->endOfMonth());
    }

    public function onDay(DateTimeInterface|string $day): static
    {
        $day = CarbonImmutable::parse($day);

        return $this->between($day->startOfDay(), $day->endOfDay());
    }

    /**
     * inYear / inMonth / onDay in one call: dated(2026), dated(2026, 10), dated(2026, 10, 23).
     */
    public function dated(int $year, ?int $month = null, ?int $day = null): static
    {
        return match (true) {
            $month === null => $this->inYear($year),
            $day === null => $this->inMonth($year, $month),
            default => $this->onDay(CarbonImmutable::create($year, $month, $day)),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Loading and ordering (chainable)
    |--------------------------------------------------------------------------
    */

    /**
     * @param  string|array<int|string, string|Closure>  $relations
     */
    public function with(string|array $relations, string|Closure|null $callback = null): static
    {
        // Eloquent reads a string's extra arguments as more relations, so a
        // null callback must not be passed on.
        $callback === null ? $this->query->with($relations) : $this->query->with($relations, $callback);

        return $this;
    }

    /**
     * @param  string|array<int, string>  $relations
     */
    public function without(string|array $relations): static
    {
        $this->query->without($relations);

        return $this;
    }

    /**
     * @param  string|array<int|string, string|Closure>  $relations
     */
    public function withCount(string|array $relations): static
    {
        $this->query->withCount($relations);

        return $this;
    }

    /**
     * Load one of the resource's named presets ("card", "full"…).
     */
    public function preset(string ...$names): static
    {
        $presets = $this->resource->presets();

        foreach ($names as $name) {
            $preset = $presets[$name] ?? throw InvalidResource::unknownPreset($this->resource->key(), $name);

            $preset instanceof Closure ? $preset($this->query) : $this->query->with($preset);
        }

        return $this;
    }

    /**
     * @param  array<int, string>|string  $columns
     */
    public function select(array|string $columns): static
    {
        $this->query->select($columns);

        return $this;
    }

    public function latest(?string $column = null): static
    {
        $this->query->orderByDesc($column ?? $this->resource->dateColumn() ?? $this->query->getModel()->getKeyName());

        return $this;
    }

    public function oldest(?string $column = null): static
    {
        $this->query->orderBy($column ?? $this->resource->dateColumn() ?? $this->query->getModel()->getKeyName());

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $this->query->orderBy($column, $direction);

        return $this;
    }

    /**
     * Sort by a spec: "-published_at,title" ("-" means descending).
     *
     * @param  string|array<int, string>  $spec
     */
    public function sort(string|array $spec): static
    {
        foreach (is_string($spec) ? explode(',', $spec) : $spec as $part) {
            if (($part = trim($part)) === '') {
                continue;
            }

            $direction = str_starts_with($part, '-') ? 'desc' : 'asc';
            $name = ltrim($part, '-+');

            // Declared sorts (closures included) first; any other column as given.
            if (! $this->resource->applySort($this->query, $name, $direction)) {
                $this->query->orderBy($name, $direction);
            }
        }

        return $this;
    }

    public function inRandomOrder(): static
    {
        $this->query->inRandomOrder();

        return $this;
    }

    /**
     * Most popular first, as the resource defines it.
     */
    public function popular(): static
    {
        $this->resource->popular($this->query);

        return $this;
    }

    /**
     * Drop every order so far (the default order then applies again).
     */
    public function unordered(): static
    {
        $this->query->reorder();

        return $this;
    }

    /**
     * Default page size for paginate(), simplePaginate() and cursorPaginate().
     */
    public function pageSize(int $perPage): static
    {
        $this->pageSize = max(1, $perPage);

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->query->limit($limit);

        return $this;
    }

    public function take(int $limit): static
    {
        return $this->limit($limit);
    }

    public function offset(int $offset): static
    {
        $this->query->offset($offset);

        return $this;
    }

    public function skip(int $offset): static
    {
        return $this->offset($offset);
    }

    /**
     * Apply ?filter[]=, ?sort=, ?include= and ?q= from a request, within
     * what the resource allows.
     */
    public function fromRequest(?Request $request = null): static
    {
        $this->manager->requestQuery()->apply($this, $request ?? request());

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Caching (chainable)
    |--------------------------------------------------------------------------
    */

    /**
     * Cache what the terminal method returns, for $seconds (default: config
     * retrive.cache.ttl). Changes to the resource's models invalidate it.
     */
    public function cache(?int $seconds = null): static
    {
        $this->ttl = $seconds ?? (int) $this->manager->config('cache.ttl', 600);

        return $this;
    }

    /**
     * Never read or write the cache, even when the resource sets a TTL.
     */
    public function fresh(): static
    {
        $this->ttl = false;

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Results (terminal)
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<int, string>|string  $columns
     */
    public function get(?int $limit = null, array|string $columns = ['*']): Collection
    {
        return $this->run('get', [$limit, $columns], fn (Builder $query) => $query->when($limit !== null, fn (Builder $query) => $query->limit($limit))->get($columns));
    }

    public function first(): ?Model
    {
        return $this->run('first', [], fn (Builder $query) => $query->first());
    }

    public function firstOrFail(): Model
    {
        return $this->first() ?? throw $this->notFound();
    }

    /**
     * The only matching record; throws when there are none or several.
     */
    public function sole(): Model
    {
        return $this->run('sole', [], fn (Builder $query) => $query->sole());
    }

    public function count(): int
    {
        return (int) $this->run('count', [], fn (Builder $query) => $query->count(), ordered: false);
    }

    public function exists(): bool
    {
        return (bool) $this->run('exists', [], fn (Builder $query) => $query->exists(), ordered: false);
    }

    public function doesntExist(): bool
    {
        return ! $this->exists();
    }

    public function pluck(string $column, ?string $key = null): BaseCollection
    {
        return $this->run('pluck', [$column, $key], fn (Builder $query) => $query->pluck($column, $key));
    }

    /**
     * Keys of the matching records, in order.
     *
     * @return array<int, int|string>
     */
    public function ids(?int $limit = null): array
    {
        $model = $this->query->getModel();
        $cast = $model->getKeyType() === 'int' ? fn ($id) => (int) $id : fn ($id) => $id;

        return array_map($cast, $this->run('ids', [$limit], fn (Builder $query) => $query
            ->when($limit !== null, fn (Builder $query) => $query->limit($limit))
            ->pluck($model->getQualifiedKeyName())
            ->all()));
    }

    public function value(string $column): mixed
    {
        return $this->run('value', [$column], fn (Builder $query) => $query->value($column));
    }

    public function sum(string $column): int|float
    {
        return $this->aggregate('sum', $column) ?? 0;
    }

    public function avg(string $column): int|float|null
    {
        return $this->aggregate('avg', $column);
    }

    public function min(string $column): mixed
    {
        return $this->aggregate('min', $column);
    }

    public function max(string $column): mixed
    {
        return $this->aggregate('max', $column);
    }

    /**
     * Number of matching records per value of a column: [value => count].
     *
     * @return BaseCollection<int|string, int>
     */
    public function countBy(string $column): BaseCollection
    {
        return $this->run('countBy', [$column], function (Builder $query) use ($column) {
            $qualified = $query->qualifyColumn($column);

            return $query->toBase()
                ->reorder()
                ->whereNotNull($qualified)
                ->selectRaw($query->getQuery()->getGrammar()->wrap($qualified).' as retrive_group, count(*) as retrive_count')
                ->groupBy($qualified)
                ->pluck('retrive_count', 'retrive_group')
                ->map(fn ($count) => (int) $count);
        }, ordered: false);
    }

    /**
     * Up to $count random matching records.
     */
    public function random(int $count = 1): Collection
    {
        return $this->run('random', [$count], fn (Builder $query) => $query->reorder()->inRandomOrder()->limit($count)->get(), ordered: false);
    }

    public function paginate(?int $perPage = null, ?int $page = null, string $pageName = 'page'): LengthAwarePaginator
    {
        $perPage = $this->perPage($perPage);
        $page = max(1, $page ?? PageResolver::resolveCurrentPage($pageName));

        [$items, $total] = $this->run('paginate', [$perPage, $page], function (Builder $query) use ($perPage, $page) {
            $paginator = $query->paginate($perPage, ['*'], 'page', $page);

            return [$paginator->getCollection(), $paginator->total()];
        });

        // Rebuilt on every call, so a cached page never carries another request's URL.
        return new Paginator($items, $total, $perPage, $page, [
            'path' => PageResolver::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * A page without a total count. Not cached.
     */
    public function simplePaginate(?int $perPage = null, ?int $page = null, string $pageName = 'page'): SimplePaginator
    {
        return $this->execute('simplePaginate', fn (Builder $query) => $query->simplePaginate($this->perPage($perPage), ['*'], $pageName, $page));
    }

    /**
     * A cursor page, for infinite scroll over large tables. Not cached.
     */
    /**
     * @param  array<int, string>|string  $columns
     */
    public function cursorPaginate(?int $perPage = null, ?string $cursor = null, string $cursorName = 'cursor', array|string $columns = ['*']): CursorPaginator
    {
        return $this->execute('cursorPaginate', fn (Builder $query) => $query->cursorPaginate($this->perPage($perPage), $columns, $cursorName, $cursor));
    }

    /**
     * @param  callable(Collection, int): mixed  $callback
     */
    public function chunk(int $size, callable $callback): bool
    {
        return $this->execute('chunk', fn (Builder $query) => $query->chunk($size, $callback));
    }

    public function lazy(int $chunkSize = 1000): LazyCollection
    {
        return $this->execute('lazy', fn (Builder $query) => $query->lazy($chunkSize));
    }

    /**
     * @param  callable(Model, int): mixed  $callback
     */
    public function each(callable $callback, int $chunkSize = 1000): bool
    {
        return $this->execute('each', fn (Builder $query) => $query->each($callback, $chunkSize));
    }

    /**
     * The record just before this one (older), within the same column values.
     *
     * @param  string|array<int, string>  $within  e.g. 'category_id'
     * @param  string|null  $by  the ordering column (default: the date column, then the key)
     */
    public function previous(Model $model, string|array $within = [], ?string $by = null): ?Model
    {
        return $this->neighbour($model, (array) $within, $by, '<');
    }

    /**
     * The record just after this one (newer), within the same column values.
     *
     * @param  string|array<int, string>  $within
     */
    public function next(Model $model, string|array $within = [], ?string $by = null): ?Model
    {
        return $this->neighbour($model, (array) $within, $by, '>');
    }

    /**
     * The Eloquent builder as it will run: visibility applied, default order
     * added. For anything the retriever does not cover.
     */
    public function query(bool $ordered = true): Builder
    {
        $query = clone $this->query;

        if ($this->visibility) {
            $this->resource->visible($query);
        }

        if ($ordered && $this->unorderedQuery($query)) {
            foreach ($this->resource->defaultSort() as $part) {
                $query->orderBy($query->qualifyColumn(ltrim($part, '-+')), str_starts_with($part, '-') ? 'desc' : 'asc');
            }
        }

        return $query;
    }

    public function toSql(): string
    {
        return $this->query()->toSql();
    }

    public function toRawSql(): string
    {
        return $this->query()->toRawSql();
    }

    /*
    |--------------------------------------------------------------------------
    | Dynamic calls
    |--------------------------------------------------------------------------
    */

    /**
     * - macros
     * - by{Field}[And{Field}…](...$values): ->byCategory('news'), ->byCategoryAndRegion($c, $r)
     * - model scopes: ->breaking(), ->flagged('approved')
     * - any Eloquent builder method that constrains the query (whereDate, join…)
     *
     * @param  array<int, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        if (preg_match('/^by([A-Z_a-z].*)$/', $method, $matches)) {
            $fields = Values::fields($matches[1]);

            if (count($parameters) < count($fields)) {
                throw UnknownFinder::arguments($method, count($fields), count($parameters));
            }

            foreach ($fields as $index => $field) {
                $this->whereField($field, $parameters[$index]);
            }

            return $this;
        }

        if ($this->query->hasNamedScope($method)) {
            $this->query->{$method}(...$parameters);

            return $this;
        }

        // Probe on a copy first: only methods that return a builder are kept,
        // so nothing can run around the visibility rule.
        $result = $this->query()->{$method}(...$parameters);

        if ($result instanceof Builder || $result instanceof QueryBuilder) {
            $this->query->{$method}(...$parameters);

            return $this;
        }

        return $result;
    }

    public function __clone(): void
    {
        $this->query = clone $this->query;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Run a terminal through the cache (when enabled) and the Retrieved event.
     *
     * @param  array<int, mixed>  $arguments
     * @param  Closure(Builder): mixed  $callback
     */
    protected function run(string $operation, array $arguments, Closure $callback, bool $ordered = true): mixed
    {
        $ttl = $this->ttl === false ? null : ($this->ttl ?? $this->resource->ttl());

        if ($ttl === null || $ttl <= 0 || ! $this->manager->cachingEnabled()) {
            return $this->execute($operation, $callback, $ordered);
        }

        $key = $this->cacheKey($operation, $arguments, $ordered);
        $started = microtime(true);
        $hit = true;

        $result = $this->manager->cacheStore()->remember($key, $ttl, function () use ($callback, $ordered, &$hit) {
            $hit = false;

            return $callback($this->query($ordered));
        });

        $this->dispatch($operation, $started, $hit);

        return $result;
    }

    /**
     * @param  Closure(Builder): mixed  $callback
     */
    protected function execute(string $operation, Closure $callback, bool $ordered = true): mixed
    {
        $started = microtime(true);
        $query = $this->query($ordered);
        $result = $callback($query);

        $this->dispatch($operation, $started, false, $query);

        return $result;
    }

    /**
     * The key leaves out the visibility rule's own clauses: those usually
     * bind "now", which would make every key unique. The flag is enough; the
     * TTL bounds how late a scheduled record appears.
     *
     * @param  array<int, mixed>  $arguments
     */
    protected function cacheKey(string $operation, array $arguments, bool $ordered): string
    {
        $query = $this->query;
        $base = $query->getQuery();

        $fingerprint = [
            $query->getModel()->getConnectionName(),
            $base->toSql(),
            $this->bindings($base->getBindings()),
            array_keys($query->getEagerLoads()),
            $this->visibility,
            $ordered && $this->unorderedQuery($query),
            $operation,
            $this->bindings($arguments),
        ];

        $generation = $this->manager->generations()->current($this->resource->key());

        return sprintf(
            '%s:%s:%d:%s',
            $this->manager->config('cache.prefix', 'retrive'),
            $this->resource->key(),
            $generation,
            hash('xxh128', serialize($fingerprint)),
        );
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return array<int|string, mixed>
     */
    private function bindings(array $values): array
    {
        return array_map(fn ($value) => match (true) {
            $value instanceof Model => [get_class($value), $value->getKey()],
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s.u'),
            $value instanceof \BackedEnum => $value->value,
            is_array($value) => $this->bindings($value),
            is_object($value) && method_exists($value, '__toString') => (string) $value,
            is_object($value) => spl_object_hash($value),
            default => $value,
        }, $values);
    }

    protected function dispatch(string $operation, float $started, bool $fromCache, ?Builder $query = null): void
    {
        if (! $this->manager->config('events', false)) {
            return;
        }

        $this->manager->events()->dispatch(new Retrieved(
            resource: $this->resource->key(),
            operation: $operation,
            milliseconds: round((microtime(true) - $started) * 1000, 2),
            fromCache: $fromCache,
            sql: $query?->toSql(),
        ));
    }

    /**
     * @param  array<int, string>  $within
     */
    protected function neighbour(Model $model, array $within, ?string $by, string $operator): ?Model
    {
        $query = $this->query(ordered: false);
        $key = $model->getKeyName();
        $direction = $operator === '<' ? 'desc' : 'asc';
        $column = $by ?? $this->resource->dateColumn();

        foreach ($within as $field) {
            $query->where($query->qualifyColumn($field), $model->getAttribute($field));
        }

        $query->whereKeyNot($model->getKey());

        if ($column === null || $column === $key || $model->getAttribute($column) === null) {
            $query->where($query->qualifyColumn($key), $operator, $model->getKey());
        } else {
            $value = $model->getAttribute($column);
            $qualified = $query->qualifyColumn($column);

            // Ties on the date are broken by the key, so equal dates never skip a record.
            $query->where(fn (Builder $query) => $query
                ->where($qualified, $operator, $value)
                ->orWhere(fn (Builder $query) => $query
                    ->where($qualified, $value)
                    ->where($query->qualifyColumn($key), $operator, $model->getKey())));

            $query->orderBy($qualified, $direction);
        }

        $started = microtime(true);
        $result = $query->orderBy($query->qualifyColumn($key), $direction)->first();
        $this->dispatch($operator === '<' ? 'previous' : 'next', $started, false, $query);

        return $result;
    }

    protected function aggregate(string $function, string $column): mixed
    {
        return $this->run($function, [$column], fn (Builder $query) => $query->{$function}($column), ordered: false);
    }

    protected function relationNamed(string $field): ?string
    {
        $model = $this->query->getModel();

        foreach (array_unique([$field, Str::plural($field), Str::singular($field)]) as $name) {
            if (RelationFilter::relation($model, $name) !== null) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Related slugs and tree descendants resolve among the related
     * resource's visible records, unless withHidden() came first.
     */
    protected function relationFilter(): RelationFilter
    {
        return new RelationFilter(
            fn (Model $related) => $this->manager->slugColumnFor($related),
            fn (Model $related) => $this->visibility ? $this->manager->visibleQuery($related) : $related->newQuery(),
        );
    }

    /**
     * The visibility callback of the model at the end of a relation path ("posts", "author.user").
     */
    protected function visibleCallback(string $relation, ?Closure $constraint = null): Closure
    {
        $model = $this->query->getModel();

        foreach (explode('.', $relation) as $segment) {
            $instance = RelationFilter::relation($model, $segment)
                ?? throw InvalidResource::unknownField($this->resource->key(), $relation);
            $model = $instance->getRelated();
        }

        return $this->manager->visible($model, $constraint);
    }

    /**
     * withCount / with arguments with each relation's visibility rule added.
     *
     * @param  string|array<int|string, string|Closure>  $relations
     * @return array<string, Closure>
     */
    protected function visibleRelations(string|array $relations): array
    {
        $result = [];

        foreach ((array) $relations as $key => $value) {
            [$name, $constraint] = is_string($key) ? [$key, $value] : [$value, null];
            $relation = trim(explode(' as ', $name)[0]);

            $result[$name] = $this->visibleCallback($relation, $constraint instanceof Closure ? $constraint : null);
        }

        return $result;
    }

    protected function unorderedQuery(Builder $query): bool
    {
        $base = $query->getQuery();

        return empty($base->orders) && empty($base->unionOrders);
    }

    protected function dateColumn(): string
    {
        $column = $this->resource->dateColumn() ?? throw InvalidResource::undated($this->resource->key());

        return $this->query->qualifyColumn($column);
    }

    protected function moment(DateTimeInterface|string $moment): CarbonInterface
    {
        return $moment instanceof CarbonInterface ? $moment : CarbonImmutable::parse($moment);
    }

    protected function perPage(?int $perPage): int
    {
        $perPage ??= $this->pageSize ?? $this->resource->perPage() ?? (int) $this->manager->config('per_page', 15);

        return max(1, $perPage);
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    protected function notFound(array $ids = []): ModelNotFoundException
    {
        return (new ModelNotFoundException)->setModel($this->resource->model(), $ids);
    }

    /**
     * @param  array<int, Model>  $models
     */
    protected function newCollection(array $models = []): Collection
    {
        return $this->query->getModel()->newCollection($models);
    }
}
