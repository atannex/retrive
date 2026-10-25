# atannex/retrive

A retrieval layer for Laravel Eloquent models. Describe each model once as a **resource**: how records are named, which ones the public may see, what to eager load, how to search, sort and filter them. Then retrieve them with magic finders or a fluent retriever.

```php
Retrive::getPostBySlug('kdwgpy2aqi');
Retrive::getPostById(42);
Retrive::getPostsByCategory('news', 6);            // news and its subcategories
Retrive::getPostsByCategoryAndRegion($news, 'fontem');
Retrive::paginatePostsByTag('flood', 12);

Retrive::posts()
    ->preset('card')
    ->search('flood fontem')
    ->inYear(2026)
    ->fromRequest()                                 // ?filter[region]=alou&sort=-published_at
    ->cache()
    ->paginate();
```

- **Resources**: model, slug column (or `relation.column`), date column, visibility rule, default eager loads, presets, searchable columns, request filters, sorts and includes, trees, cache dependencies, aliases.
- **Visibility**: drafts and hidden records never leak. Every retrieval applies the resource's rule unless you call `withHidden()`.
- **Magic finders**: `{operation}{Resource}[By{Field}[And{Field}…]][OrFail]`. Field names ignore case, so `getPostBycategory` works too.
- **Relation filters**: belongs-to, has-many, many-to-many, morph-to. Related records can be given as models, keys or slugs, one or many, or as a comma list. Filtering by a tree node also matches its descendants.
- **Search**: portable LIKE across columns and relations. Every word must match, `"phrases"` stay whole, and wildcards are escaped.
- **Dates**: `since`, `until`, `between`, `recent`, `inYear`, `inMonth`, `onDay`, `dated`.
- **Neighbours and related records**: `previous`/`next` within a category, and `relatedTo($post, via: 'tags')` ranked by shared tags.
- **Request queries**: whitelisted `?filter[]=`, `?sort=`, `?include=`, `?q=` and `?per_page=`. Anything not allowed gets a 400.
- **Caching**: opt-in per call or per resource. The cache invalidates itself when a resource's model or one of its dependencies is saved, deleted or restored, and it works on every store with no cache tags needed.
- **Tooling**: `retrive:list`, `retrive:sql`, `retrive:clear`, `make:retrive-resource`, and an optional `Retrieved` event for profiling.

## Install

```bash
composer require atannex/retrive
php artisan vendor:publish --tag=retrive-config
```

## Resources

```php
use Atannex\Retrive\Resource;
use Atannex\Retrive\Retriever;
use Illuminate\Database\Eloquent\Builder;

final class PostResource extends Resource
{
    protected string $model = Post::class;

    protected ?string $date = 'published_at';           // latest(), since(), inYear(), previous()…
    protected string $slug = 'slug';                     // or 'user.slug' through a relation
    protected array $with = ['media'];                   // always eager loaded
    protected array $presets = ['card' => ['category', 'author.user']];
    protected array $searchable = ['title', 'description', 'tags.name'];
    protected array $hierarchies = ['category' => 'parent_id'];   // category filters include subcategories
    protected array $aliases = ['path' => 'slug_path'];  // getPostByPath()
    protected array $filters = ['category', 'region', 'tag' => 'tags', 'search' => 'search'];
    protected array $sorts = ['published_at', 'title'];
    protected array $includes = ['tags', 'category'];
    protected array $dependsOn = [PostTag::class, Category::class];  // their writes flush this cache
    protected ?int $ttl = null;                          // cache every retrieval (seconds)
    protected ?int $perPage = 12;

    public function visible(Builder $query): void        // what the public may see
    {
        $query->published();
    }

    public function popular(Builder $query): void        // ->popular()
    {
        $query->orderByDesc('views');
    }
}
```

Register resources in `config/retrive.php`. A plain model class gets a resource built from conventions:

```php
'resources' => [
    App\Retrieval\PostResource::class,
    'people' => App\Retrieval\AuthorResource::class,   // the key overrides the resource's own
    App\Models\Testimonial::class,
],
```

The key (plural snake case of the model by default) names the resource everywhere: `Retrive::for('posts')`, `Retrive::posts()`, `getPostBySlug()`. Models that aren't registered still work through `Retrive::for(Model::class)` or the `Retrievable` trait (`Post::retrieve()`). They get a default resource.

Closures can't go in property defaults, so set closure filters and sorts in the constructor:

```php
public function __construct()
{
    $this->filters['year'] = fn (Retriever $posts, $year) => $posts->inYear((int) $year);
    $this->sorts['views'] = fn (Builder $query, string $direction) => $query->orderBy('views', $direction);
}
```

Resources are resolved from the container, so constructor injection works.

## Magic finders

```
{operation}{Resource}[By{Field}[And{Field}…]][OrFail](...$values, ?int $limit, ?Closure $tweak)
```

| Operation | Singular (`Post`) | Plural (`Posts`) |
|---|---|---|
| `get`, `find`, `first` | first match or null | collection (at most `default_limit` unless a limit is given) |
| `latest`, `oldest` | newest or oldest match | collection, newest or oldest first |
| `random` | one random match | `$limit` random matches (default 1) |
| `count`, `exists` | int or bool | int or bool |
| `paginate` | — | `LengthAwarePaginator` (`$perPage`, `$page`) |

`OrFail` throws `ModelNotFoundException` (a 404) when nothing matches. Pass one value per field. After the values come an optional limit and an optional closure that can adjust the retriever:

```php
Retrive::getPostsByCategory('news', 6, fn (Retriever $r) => $r->preset('card')->breaking());
```

A field resolves to the first of these that matches:

1. a resource **alias**: a column, a relation or a closure;
2. `id` / `key`: the primary key (a list means IN);
3. `slug`: the resource's slug column;
4. a **relation** (singular or plural name). The value can be models, keys, slugs or a comma list. Descendants are included for `$hierarchies`;
5. otherwise the **snake_case column**. A list means IN, and null means IS NULL.

So `getPostsByAuthor('ada')`, `getPostsByTag($tag)`, `getPostsByRegion('fontem,alou')`, `getPostsByAuthorId(3)` and `getPostByTitle('…')` all work without extra code. A numeric string is read as a key, anything else as a slug.

## The retriever

```php
$posts = Retrive::posts();     // or Retrive::for('posts'), Post::retrieve()
```

**Lookups**: `find($id)`, `findOrFail`, `findMany($ids)` (in the order given), `findBySlug`, `findBySlugOrFail`, `findBy($field, $value)`, `findByOrFail`.

**Filters**: `whereField`, `by{Field}[And{Field}]`, `belongingTo($relation, $value, descendants: null)`, `withDescendants()`/`withoutDescendants()`, `search($term, ?$columns)` (a model with its own `search` scope, such as atannex/ecosystem's `Searchable`, is searched through it: `->search($parsed, $options)`; passing a column list forces the plain search), `except($records)`, `scope('name', ...)` or any model scope by name (`->breaking()`), `apply(Criterion|Closure ...)`, `tap(fn (Builder $q) => …)`, plus any Eloquent method that constrains the query (`whereDate`, `join`…).

**Visibility**: `withHidden()`, `withTrashed()`, `onlyTrashed()`.

**Related visibility**: `whereHasVisible($relation, ?fn)`, `whereDoesntHaveVisible($relation, ?fn)`, `withCountVisible('posts as usage_count')` / `withCountVisible(['posts as n' => fn])`, `withVisible($relations)`. Each applies the *related* resource's rule inside the whereHas / withCount / with closure, so "tags ranked by published posts" never repeats the posts rule:

```php
Retrive::tags()->whereHasVisible('posts')->withCountVisible('posts')->orderByDesc('posts_count')->get(10);
```

Outside a retriever, `Retrive::visible('posts', ?fn)` returns the rule as a closure (`Tag::query()->withCount(['posts' => Retrive::visible('posts')])`, `$tag->posts()->where(Retrive::visible('posts'))`), and `Retrive::visibleQuery(Post::class)` a fresh query with it applied.

Relation filters resolve slugs and tree descendants among the related resource's **visible** records: a hidden category's slug matches nothing, and a hidden node prunes its whole branch. Models and keys passed directly are taken as they are. Call `withHidden()` before the filter to include hidden related records.

**Dates** (on the resource's date column): `since`, `until`, `between`, `recent($days)`, `inYear`, `inMonth`, `onDay`, `dated($year, $month, $day)`.

**Loading**: `with`, `without`, `withCount`, `preset('card')`, `select`.

**Order and size**: `latest`, `oldest`, `orderBy`, `sort('-published_at,title')`, `inRandomOrder`, `popular`, `unordered`, `limit`/`take`, `offset`/`skip`, `pageSize`. Without an order, the resource's `defaultSort` applies (the date descending, then the key). `sort()` uses the resource's declared `$sorts` (closures included, e.g. `-views`) and treats any other name as a column.

`$resource->applySort($query, 'views', 'desc')` applies a declared sort to any query or relation (eager-load constraints included) and returns false for an undeclared name, so admin "sort by" options can be whitelisted by the resource.

**Related records**: `relatedTo($model, via: 'tags', sameAs: 'category_id')` ranks records by the number of `via` records they share.

**Terminals**: `get(?$limit, $columns = ['*'])`, `first`, `firstOrFail`, `sole`, `count`, `exists`, `doesntExist`, `pluck`, `ids(?$limit)`, `value`, `sum`/`avg`/`min`/`max`, `countBy($column)` (`[value => count]`), `random($n)`, `paginate`, `simplePaginate`, `cursorPaginate(…, columns:)`, `chunk`, `lazy`, `each`, `previous($model, within:, by:)`, `next(...)`.

**Inspection**: `query()` (the Eloquent builder as it will run), `toSql()`, `toRawSql()`.

A retriever can run several terminals, each on a copy of the query. Add your own methods with `Retriever::macro()`.

Visibility is applied when the query runs, so no forwarded Eloquent method can bypass it: `->toBase()->count()` still counts only visible records.

## Request queries

```php
Retrive::posts()->preset('card')->fromRequest()->paginate();
```

| Parameter | Uses | Example |
|---|---|---|
| `filter[name]` | `$filters` | `?filter[category]=news,sport&filter[year]=2026` |
| `sort` | `$sorts`, `-` for descending | `?sort=-published_at,title` |
| `include` | `$includes` | `?include=tags,author.user` |
| `q` | `$searchable` | `?q=flood fontem` |
| `per_page` | capped by `max_per_page` | `?per_page=20` |

A requested sort replaces any order set in code. In strict mode (the default), a filter, sort or include the resource doesn't allow, or a malformed parameter, throws `InvalidQuery`, which renders as 400 Bad Request. With `request.strict` set to false, they're ignored instead. Parameter names are configurable.

## Caching

```php
Retrive::posts()->byCategory('news')->cache()->get(6);     // config retrive.cache.ttl
Retrive::posts()->cache(60)->count();
Retrive::posts()->fresh()->get();                          // bypass a resource-level TTL
Retrive::flush('posts');                                   // or: php artisan retrive:clear posts
```

Every cache key includes a per-resource generation number. Saving, deleting, restoring or force-deleting a model of the resource, or of a model in `$dependsOn`, bumps that number, so stale entries are never read again and simply expire. This works on file, database, redis and every other store, with no cache tags needed.

Good to know:

- Writes that fire no model events (`saveQuietly`, query-builder `update()`, `withoutEvents`, plain pivot `attach()` without a custom pivot model) don't invalidate. Call `Retrive::flush('posts')` after them.
- The visibility rule's own clauses aren't part of the key, because they usually bind "now". A scheduled record therefore appears in cached results at most one TTL late.
- Pages are cached as items plus total, and the paginator is rebuilt for each request, so cached pages always carry the current URL.
- `simplePaginate`, `cursorPaginate`, `chunk`, `lazy`, `each` and `previous`/`next` are never cached.
- Eager-load constraint closures aren't part of the key. Two retrievals that differ only in such a closure share an entry.

## Console

```bash
php artisan retrive:list                 # every resource
php artisan retrive:list posts           # one in detail, with example finders
php artisan retrive:sql getPostsByCategory news        # the SQL a finder runs
php artisan retrive:sql countPostsByTag flood --run    # …and its result
php artisan retrive:clear [posts]
php artisan make:retrive-resource PostResource --model="App\Models\Posts\Post"
```

## Events

With `retrive.events` on, every retrieval dispatches `Atannex\Retrive\Events\Retrieved` with the resource, operation, duration in milliseconds, whether it came from the cache, and the SQL.

## Exceptions

All implement `Atannex\Retrive\Exceptions\RetriveException`.

| Exception | When | HTTP |
|---|---|---|
| `ModelNotFoundException` (Laravel's) | `…OrFail` found nothing | 404 |
| `InvalidQuery` | a request asked for something not allowed | 400 |
| `UnknownResource` | no resource has that name | 500 |
| `UnknownFinder` | a method isn't a finder, or values are missing | 500 |
| `InvalidResource` | a resource definition is wrong (no model, unknown preset, no searchable columns…) | 500 |

## Testing

```bash
cd packages/atannex/retrive && composer install && vendor/bin/phpunit
```
