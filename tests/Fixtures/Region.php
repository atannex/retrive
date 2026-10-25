<?php

namespace Atannex\Retrive\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Has its own search scope, like a model using atannex/ecosystem's Searchable.
 */
class Region extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    public function scopeSearch(Builder $query, string|\Stringable|null $terms, ?object $options = null): Builder
    {
        $terms = (string) $terms;

        return ($options->exact ?? false)
            ? $query->where('name', $terms)
            : $query->where('name', 'like', "%{$terms}%");
    }
}
