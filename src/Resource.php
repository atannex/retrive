<?php

declare(strict_types=1);

namespace Atannex\Retrive;

use Atannex\Retrive\Exceptions\InvalidResource;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

/**
 * Describes how one Eloquent model is retrieved: how records are named
 * (key, slug), which of them the public may see, what is eager loaded, how
 * they are searched and ordered, and what a request may filter on.
 *
 *     final class PostResource extends Resource
 *     {
 *         protected string $model = Post::class;
 *         protected ?string $date = 'published_at';
 *         protected array $presets = ['card' => ['category', 'author', 'media']];
 *         protected array $searchable = ['title', 'description', 'tags.name'];
 *         protected array $hierarchies = ['category' => 'parent_id'];
 *
 *         public function visible(Builder $query): void
 *         {
 *             $query->published();
 *         }
 *     }
 *
 * Everything is optional except $model; defaults follow Laravel conventions.
 */
abstract class Resource
{
    /**
     * @var class-string<Model>
     */
    protected string $model;

    /**
     * Registry key ("posts"). Defaults to the plural snake case of the model's name.
     */
    protected ?string $key = null;

    /**
     * Column records are looked up by in bySlug(). A dotted value
     * ("user.slug") looks through a relation.
     */
    protected string $slug = 'slug';

    /**
     * The column that dates a record: latest(), since(), inYear(), previous()…
     * Defaults to the model's created_at column, or none without timestamps.
     */
    protected ?string $date = null;

    /**
     * Relations always eager loaded.
     *
     * @var array<int|string, string|Closure>
     */
    protected array $with = [];

    /**
     * Relation counts always loaded.
     *
     * @var array<int|string, string|Closure>
     */
    protected array $withCount = [];

    /**
     * Named eager-load sets: ['card' => ['category', 'author.user']]. A
     * preset may also be a closure receiving the Eloquent builder.
     *
     * @var array<string, array<int|string, string|Closure>|Closure>
     */
    protected array $presets = [];

    /**
     * Columns search() matches; "relation.column" looks through a relation.
     *
     * @var array<int, string>
     */
    protected array $searchable = [];

    /**
     * Filters a request may use (?filter[name]=value).
     *
     * A list entry ("category") filters on that field the way a magic finder
     * does; a keyed entry maps a public name to a field ("tag" => "tags") or
     * to a closure: fn (Retriever $retriever, mixed $value) => …
     *
     * @var array<int|string, string|Closure>
     */
    protected array $filters = [];

    /**
     * Sorts a request may use (?sort=-published_at). A list entry is a
     * column; a keyed entry maps a public name to a column or a closure:
     * fn (Builder $query, string $direction) => …
     *
     * @var array<int|string, string|Closure>
     */
    protected array $sorts = [];

    /**
     * Relations a request may load (?include=tags,category).
     *
     * @var array<int, string>
     */
    protected array $includes = [];

    /**
     * Order used when none is asked for, e.g. ['-published_at', '-id'].
     * Defaults to the date column descending, then the key descending.
     *
     * @var array<int, string>|string
     */
    protected array|string $defaultSort = [];

    /**
     * Relations whose related model is a tree: filtering by a node also
     * matches its descendants. Maps relation => the related parent column.
     *
     * @var array<string, string>
     */
    protected array $hierarchies = [];

    /**
     * Other models whose changes invalidate this resource's cache (a pivot,
     * a related model the cached records embed).
     *
     * @var array<int, class-string<Model>>
     */
    protected array $dependsOn = [];

    /**
     * Finder field aliases: ['path' => 'slug_path', 'tag' => 'tags'], or a
     * closure: fn (Retriever $retriever, mixed $value) => …
     *
     * @var array<string, string|Closure>
     */
    protected array $aliases = [];

    /**
     * Cache every retrieval for this many seconds (null: only when asked).
     */
    protected ?int $ttl = null;

    /**
     * Default page size (null: config retrive.per_page).
     */
    protected ?int $perPage = null;

    /**
     * Restrict a query to the records the public may see (published,
     * approved, active…). Skipped by Retriever::withHidden().
     */
    public function visible(Builder $query): void
    {
        //
    }

    /**
     * Order a query by popularity, for Retriever::popular(). Throws by
     * default: popularity means something different for every model.
     */
    public function popular(Builder $query): void
    {
        throw InvalidResource::notRankable($this->key());
    }

    /**
     * Whether popular() is defined for this resource.
     */
    public function isRankable(): bool
    {
        return (new \ReflectionMethod($this, 'popular'))->getDeclaringClass()->getName() !== self::class;
    }

    /**
     * A fresh query, before visibility and defaults. Override to start from
     * a different base (another connection, a global constraint…).
     */
    public function newQuery(): Builder
    {
        return $this->newModel()->newQuery();
    }

    /**
     * @return class-string<Model>
     */
    public function model(): string
    {
        if (! isset($this->model)) {
            throw InvalidResource::missingModel(static::class);
        }

        if (! is_subclass_of($this->model, Model::class)) {
            throw InvalidResource::notAModel(static::class, $this->model);
        }

        return $this->model;
    }

    public function newModel(): Model
    {
        $model = $this->model();

        return new $model;
    }

    public function key(): string
    {
        return $this->key ??= Str::plural(Str::snake(class_basename($this->model())));
    }

    /**
     * @internal Set by the manager when config gives the resource a key.
     */
    public function withKey(string $key): static
    {
        $this->key = $key;

        return $this;
    }

    /**
     * Singular StudlyCase name used in finders: "Post" for "posts".
     */
    public function singularName(): string
    {
        return Str::studly(Str::singular($this->key()));
    }

    /**
     * Plural StudlyCase name used in finders: "Posts" for "posts".
     */
    public function pluralName(): string
    {
        return Str::studly(Str::plural($this->key()));
    }

    public function slugColumn(): string
    {
        return $this->slug;
    }

    public function dateColumn(): ?string
    {
        if ($this->date !== null) {
            return $this->date;
        }

        $model = $this->newModel();

        return $model->usesTimestamps() ? $model->getCreatedAtColumn() : null;
    }

    /**
     * @return array<int|string, string|Closure>
     */
    public function with(): array
    {
        return $this->with;
    }

    /**
     * @return array<int|string, string|Closure>
     */
    public function withCount(): array
    {
        return $this->withCount;
    }

    /**
     * @return array<string, array<int|string, string|Closure>|Closure>
     */
    public function presets(): array
    {
        return $this->presets;
    }

    /**
     * @return array<int, string>
     */
    public function searchable(): array
    {
        return $this->searchable;
    }

    /**
     * Filters normalised to name => field|closure.
     *
     * @return array<string, string|Closure>
     */
    public function filters(): array
    {
        return $this->normalise($this->filters);
    }

    /**
     * Sorts normalised to name => column|closure.
     *
     * @return array<string, string|Closure>
     */
    public function sorts(): array
    {
        return $this->normalise($this->sorts);
    }

    /**
     * Order any query (or relation) by one of the declared sorts. Returns
     * false, leaving the query alone, for a name that is not declared.
     */
    public function applySort(Builder|Relation $query, string $name, string $direction = 'asc'): bool
    {
        $target = $this->sorts()[$name] ?? null;

        if ($target === null) {
            return false;
        }

        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $builder = $query instanceof Relation ? $query->getQuery() : $query;

        $target instanceof Closure
            ? $target($builder, $direction)
            : $builder->orderBy(str_contains($target, '.') ? $target : $builder->qualifyColumn($target), $direction);

        return true;
    }

    /**
     * @return array<int, string>
     */
    public function includes(): array
    {
        return array_values($this->includes);
    }

    /**
     * @return array<int, string> e.g. ['-published_at', '-id']
     */
    public function defaultSort(): array
    {
        $sort = is_string($this->defaultSort)
            ? array_filter(array_map('trim', explode(',', $this->defaultSort)))
            : $this->defaultSort;

        if ($sort !== []) {
            return array_values($sort);
        }

        $model = $this->newModel();

        return array_values(array_filter([
            ($date = $this->dateColumn()) !== null ? "-{$date}" : null,
            '-'.$model->getKeyName(),
        ]));
    }

    /**
     * @return array<string, string>
     */
    public function hierarchies(): array
    {
        return $this->hierarchies;
    }

    /**
     * @return array<int, class-string<Model>>
     */
    public function dependsOn(): array
    {
        return array_values($this->dependsOn);
    }

    /**
     * @return array<string, string|Closure>
     */
    public function aliases(): array
    {
        return collect($this->aliases)
            ->mapWithKeys(fn ($target, string $field) => [Str::camel($field) => $target])
            ->all();
    }

    public function ttl(): ?int
    {
        return $this->ttl;
    }

    public function perPage(): ?int
    {
        return $this->perPage;
    }

    /**
     * ['a', 'b' => 'c'] => ['a' => 'a', 'b' => 'c']
     *
     * @param  array<int|string, string|Closure>  $entries
     * @return array<string, string|Closure>
     */
    private function normalise(array $entries): array
    {
        $normalised = [];

        foreach ($entries as $name => $target) {
            $normalised[is_int($name) ? (string) $target : $name] = $target;
        }

        return $normalised;
    }
}
