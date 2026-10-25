<?php

namespace Atannex\Retrive\Tests\Fixtures;

use Atannex\Retrive\Resource;
use Atannex\Retrive\Retriever;
use Illuminate\Database\Eloquent\Builder;

final class ArticleResource extends Resource
{
    protected string $model = Article::class;

    protected ?string $date = 'published_at';

    protected array $presets = ['card' => ['category', 'region', 'author.user']];

    protected array $searchable = ['title', 'body', 'tags.name'];

    protected array $hierarchies = ['category' => 'parent_id'];

    protected array $aliases = ['path' => 'slug_path'];

    protected array $filters = ['category', 'region', 'tag' => 'tags', 'search' => 'search'];

    protected array $sorts = ['published_at', 'title', 'views'];

    protected array $includes = ['tags', 'category', 'author.user'];

    protected array $dependsOn = [Tag::class];

    public function __construct()
    {
        $this->filters['year'] = fn (Retriever $retriever, $year) => $retriever->inYear((int) $year);
    }

    public function visible(Builder $query): void
    {
        $query->published();
    }

    public function popular(Builder $query): void
    {
        $query->orderByDesc('views');
    }
}
