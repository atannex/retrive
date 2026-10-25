<?php

namespace Atannex\Retrive\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class);
    }
}
