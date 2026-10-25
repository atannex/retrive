<?php

namespace Atannex\Retrive\Tests\Fixtures;

use Atannex\Retrive\Resource;

final class AuthorResource extends Resource
{
    protected string $model = Author::class;

    protected string $slug = 'user.slug';
}
