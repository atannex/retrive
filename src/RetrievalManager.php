<?php

declare(strict_types=1);

namespace Atannex\Retrive;

use Atannex\Retrive\Cache\Generations;
use Atannex\Retrive\Exceptions\InvalidResource;
use Atannex\Retrive\Exceptions\UnknownFinder;
use Atannex\Retrive\Exceptions\UnknownResource;
use Atannex\Retrive\Finders\Finder;
use Atannex\Retrive\Finders\FinderParser;
use Atannex\Retrive\Http\RequestQuery;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

/**
 * The registry of resources and the entry point of every retrieval.
 *
 *     Retrive::for('posts')            a Retriever
 *     Retrive::posts()                 the same, by key
 *     Retrive::for(Post::class)        by model (unregistered models get a GenericResource)
 *     Retrive::getPostBySlug($slug)    magic finders (see FinderParser)
 *     Retrive::flush('posts')          drop the resource's cached retrievals
 */
class RetrievalManager
{
    /**
     * @var array<string, resource> key => resource
     */
    private array $resources = [];

    /**
     * @var array<class-string<Model>, string> model => key of its resource
     */
    private array $models = [];

    /**
     * @var array<class-string<Model>, array<int, string>> model => keys whose cache it invalidates
     */
    private array $watchers = [];

    /**
     * @var array<string, Finder|false> parsed finder names
     */
    private array $finders = [];

    private ?Generations $generations = null;

    private ?RequestQuery $requestQuery = null;

    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
        private readonly CacheFactory $cache,
        private readonly Dispatcher $events,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Registry
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<int|string, class-string|resource>  $entries  as in config retrive.resources
     */
    public function registerMany(array $entries): static
    {
        foreach ($entries as $key => $resource) {
            $this->register($resource, is_string($key) ? $key : null);
        }

        return $this;
    }

    /**
     * Register a Resource (class or instance) or a plain model class.
     *
     * @param  class-string|resource  $resource
     */
    public function register(string|Resource $resource, ?string $key = null): Resource
    {
        $resource = $this->instantiate($resource);

        if ($key !== null) {
            $resource->withKey($key);
        }

        $key = $resource->key();
        $model = $resource->model();

        $this->resources[$key] = $resource;
        $this->models[$model] ??= $key;
        $this->finders = [];

        foreach ([$model, ...$resource->dependsOn()] as $watched) {
            $this->watch($watched, $key);
        }

        return $resource;
    }

    /**
     * The resource named by a key ("posts", "post"), a model class or
     * instance, or a Resource class. Unregistered models get a default
     * resource, registered on the fly.
     */
    public function resource(string|Model|Resource $name): Resource
    {
        if ($name instanceof Resource) {
            return $name;
        }

        if ($name instanceof Model) {
            $name = $name::class;
        }

        if (($resource = $this->find($name)) !== null) {
            return $resource;
        }

        if (class_exists($name) && (is_subclass_of($name, Model::class) || is_subclass_of($name, Resource::class))) {
            return $this->register($name);
        }

        throw UnknownResource::named($name, array_keys($this->resources));
    }

    public function has(string $name): bool
    {
        return $this->find($name) !== null;
    }

    /**
     * @return array<string, resource>
     */
    public function resources(): array
    {
        return $this->resources;
    }

    /**
     * A new retriever for a resource.
     */
    public function for(string|Model|Resource $name): Retriever
    {
        return new Retriever($this->resource($name), $this);
    }

    /**
     * A resource's visibility rule as a query callback, for the places a
     * retriever cannot reach: whereHas, withCount and eager-load closures.
     *
     *     Tag::query()->withCount(['posts' => Retrive::visible('posts')]);
     *     $tag->posts()->where(Retrive::visible('posts', fn ($q) => $q->where('region_id', 3)));
     *
     * @param  (Closure(Builder): mixed)|null  $constraint  applied after the rule
     * @return Closure(Builder|Relation): void
     */
    public function visible(string|Model|Resource $name, ?Closure $constraint = null): Closure
    {
        $resource = $this->resource($name);

        return function (Builder|Relation $query) use ($resource, $constraint): void {
            $builder = $query instanceof Relation ? $query->getQuery() : $query;

            $resource->visible($builder);

            if ($constraint !== null) {
                $constraint($query);
            }
        };
    }

    /**
     * A new query on a model's records, its resource's visibility rule applied.
     */
    public function visibleQuery(string|Model $model): Builder
    {
        $resource = $this->resource($model);
        $query = $resource->newQuery();
        $resource->visible($query);

        return $query;
    }

    /**
     * The column a model's records are named by in slugs: its resource's
     * slug column when registered, else "slug".
     */
    public function slugColumnFor(Model $model): string
    {
        $key = $this->models[$model::class] ?? null;

        return $key !== null ? $this->resources[$key]->slugColumn() : 'slug';
    }

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    */

    /**
     * Invalidate the cached retrievals of one resource, or of all of them.
     */
    public function flush(?string $name = null): void
    {
        $keys = $name === null ? array_keys($this->resources) : [$this->resource($name)->key()];

        foreach ($keys as $key) {
            $this->generations()->bump($key);
        }
    }

    /**
     * Called when a watched model is saved, deleted or restored.
     */
    public function modelChanged(Model|string $model): void
    {
        $class = $model instanceof Model ? $model::class : $model;

        foreach ($this->watchers[$class] ?? [] as $key) {
            $this->generations()->bump($key);
        }
    }

    public function cachingEnabled(): bool
    {
        return (bool) $this->config('cache.enabled', true);
    }

    public function cacheStore(): CacheRepository
    {
        return $this->cache->store($this->config('cache.store'));
    }

    public function generations(): Generations
    {
        return $this->generations ??= new Generations($this->cacheStore(), (string) $this->config('cache.prefix', 'retrive'));
    }

    /*
    |--------------------------------------------------------------------------
    | Collaborators
    |--------------------------------------------------------------------------
    */

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config->get("retrive.{$key}", $default);
    }

    /**
     * Resolved on use, so a dispatcher swapped later (Event::fake()) is honoured.
     */
    public function events(): Dispatcher
    {
        return $this->container->make('events');
    }

    public function requestQuery(): RequestQuery
    {
        return $this->requestQuery ??= new RequestQuery(
            (array) $this->config('request', []),
            max(1, (int) $this->config('max_per_page', 100)),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Magic finders
    |--------------------------------------------------------------------------
    */

    /**
     * The parsed finder for a method name, or null when it is not one.
     */
    public function finder(string $method): ?Finder
    {
        if (! array_key_exists($method, $this->finders)) {
            $this->finders[$method] = (new FinderParser(fn (string $token) => $this->resolveToken($token)))->parse($method) ?? false;
        }

        return $this->finders[$method] ?: null;
    }

    /**
     * The retriever a finder call builds, before its terminal runs: handy
     * to inspect the SQL (retrive:sql) or to add to it.
     *
     * @param  array<int, mixed>  $arguments
     * @return array{0: Retriever, 1: array<int, int>} the retriever and the trailing numeric arguments
     */
    public function prepare(Finder $finder, array $arguments): array
    {
        $fields = count($finder->fields);

        if (count($arguments) < $fields) {
            throw UnknownFinder::arguments($finder->method, $fields, count($arguments));
        }

        $retriever = $this->for($finder->resource);

        foreach ($finder->fields as $index => $field) {
            $retriever->whereField($field, $arguments[$index]);
        }

        $numbers = [];

        foreach (array_slice($arguments, $fields) as $extra) {
            match (true) {
                $extra instanceof Closure => $extra($retriever),
                is_int($extra), is_string($extra) && ctype_digit($extra) => $numbers[] = (int) $extra,
                $extra === null => null,
                default => throw new \InvalidArgumentException(
                    "[{$finder->method}] takes one value per field, then an optional limit and an optional closure."
                ),
            };
        }

        match ($finder->operation) {
            'latest' => $retriever->latest(),
            'oldest' => $retriever->oldest(),
            default => null,
        };

        return [$retriever, $numbers];
    }

    /**
     * Run a finder.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function callFinder(Finder $finder, array $arguments): mixed
    {
        [$retriever, $numbers] = $this->prepare($finder, $arguments);
        $number = $numbers[0] ?? null;

        $result = match (true) {
            $finder->operation === 'count' => $retriever->count(),
            $finder->operation === 'exists' => $retriever->exists(),
            $finder->operation === 'paginate' => $retriever->paginate($number, $numbers[1] ?? null),
            $finder->operation === 'random' => $finder->plural
                ? $retriever->random($number ?? 1)
                : $retriever->random(1)->first(),
            $finder->returnsOne() => $retriever->first(),
            // A limit set in the closure wins over the default cap.
            default => $retriever->get($number ?? ($retriever->query(false)->getQuery()->limit === null ? $this->defaultLimit() : null)),
        };

        if ($finder->orFail && ($result === null || (is_countable($result) && count($result) === 0) || $result === false)) {
            throw (new ModelNotFoundException)->setModel($finder->resource->model(), array_slice($arguments, 0, count($finder->fields)));
        }

        return $result;
    }

    /**
     * Finders on the manager, plus a retriever per resource key:
     * Retrive::posts(), Retrive::getPostBySlug($slug)…
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        if ($arguments === [] && ($resource = $this->find($method)) !== null) {
            return new Retriever($resource, $this);
        }

        if (($finder = $this->finder($method)) !== null) {
            return $this->callFinder($finder, $arguments);
        }

        throw UnknownFinder::method($method);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function find(string $name): ?Resource
    {
        if (isset($this->resources[$name])) {
            return $this->resources[$name];
        }

        if (isset($this->models[$name])) {
            return $this->resources[$this->models[$name]];
        }

        foreach ([Str::snake($name), Str::plural(Str::snake($name))] as $candidate) {
            if (isset($this->resources[$candidate])) {
                return $this->resources[$candidate];
            }
        }

        foreach ($this->resources as $resource) {
            if ($resource::class === $name) {
                return $resource;
            }
        }

        return null;
    }

    /**
     * A finder's resource token ("Post", "Posts", "BlogPosts") => [resource, plural].
     *
     * @return array{0: resource, 1: bool}|null
     */
    private function resolveToken(string $token): ?array
    {
        $token = strtolower($token);

        foreach ($this->resources as $resource) {
            $singular = strtolower($resource->singularName());
            $plural = strtolower($resource->pluralName());

            // Uncountable names ("news", "media") read as plural.
            if ($token === $plural) {
                return [$resource, true];
            }

            if ($token === $singular) {
                return [$resource, false];
            }
        }

        return null;
    }

    /**
     * @param  class-string|resource  $resource
     */
    private function instantiate(string|Resource $resource): Resource
    {
        if ($resource instanceof Resource) {
            return $resource;
        }

        if (is_subclass_of($resource, Resource::class)) {
            return $this->container->make($resource);
        }

        if (is_subclass_of($resource, Model::class)) {
            return new GenericResource($resource);
        }

        throw InvalidResource::notAResource($resource);
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function watch(string $model, string $key): void
    {
        if (! isset($this->watchers[$model])) {
            $this->watchers[$model] = [];

            $this->events->listen(
                array_map(fn (string $event) => "eloquent.{$event}: {$model}", ['saved', 'deleted', 'restored', 'forceDeleted']),
                fn () => $this->modelChanged($model),
            );
        }

        if (! in_array($key, $this->watchers[$model], true)) {
            $this->watchers[$model][] = $key;
        }
    }

    private function defaultLimit(): ?int
    {
        $limit = $this->config('default_limit', 100);

        return $limit === null ? null : (int) $limit;
    }
}
