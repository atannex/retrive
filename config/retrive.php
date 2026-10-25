<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Resources
    |--------------------------------------------------------------------------
    |
    | What can be retrieved. Each entry is a Resource class, or a plain
    | Eloquent model class (it then gets a default resource by convention).
    | A string key overrides the resource's own key:
    |
    |     App\Retrieval\PostResource::class,
    |     'authors' => App\Retrieval\AuthorResource::class,
    |     App\Models\Testimonial::class,
    |
    | The key names the resource in Retrive::for('posts') and in magic
    | finders: getPostBySlug(), getPostsByCategory()…
    |
    */

    'resources' => [],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | per_page is the default page size; max_per_page caps what a request's
    | ?per_page= may ask for. Resources can override per_page.
    |
    */

    'per_page' => 15,

    'max_per_page' => 100,

    /*
    |--------------------------------------------------------------------------
    | Default limit for plural magic finders
    |--------------------------------------------------------------------------
    |
    | getPostsByCategory($category) without a limit returns at most this many
    | records, so a forgotten limit never loads a whole table. Null removes
    | the cap.
    |
    */

    'default_limit' => 100,

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Retrievals are cached only when asked (->cache()) or when a resource
    | sets a TTL. Keys carry a per-resource generation number that is bumped
    | whenever a model of the resource (or one it depends on) is saved,
    | deleted or restored, so stale results are never served and no cache
    | tags are needed (works on file, database, redis…).
    |
    */

    'cache' => [
        'enabled' => env('RETRIVE_CACHE', true),
        'store' => env('RETRIVE_CACHE_STORE'),
        'prefix' => 'retrive',
        'ttl' => 600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Request queries
    |--------------------------------------------------------------------------
    |
    | Parameter names read by ->fromRequest(). Only the filters, sorts and
    | includes a resource allows are accepted; anything else is refused with
    | a 400 (strict) or ignored.
    |
    */

    'request' => [
        'filter' => 'filter',
        'sort' => 'sort',
        'include' => 'include',
        'per_page' => 'per_page',
        'search' => 'q',
        'strict' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    |
    | Dispatch Atannex\Retrive\Events\Retrieved after each retrieval (resource,
    | operation, duration, cache hit). Handy for logging and profiling; off by
    | default to keep hot paths free.
    |
    */

    'events' => env('RETRIVE_EVENTS', false),

];
