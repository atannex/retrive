<?php

namespace Atannex\Retrive\Tests\Feature;

use Atannex\Retrive\Exceptions\InvalidQuery;
use Atannex\Retrive\Facades\Retrive;
use Atannex\Retrive\RetrievalManager;
use Atannex\Retrive\Tests\TestCase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;

class RequestQueryTest extends TestCase
{
    private function retrieving(array $query)
    {
        return Retrive::articles()->fromRequest(Request::create('/articles', 'GET', $query));
    }

    public function test_filters(): void
    {
        $this->newsroom();

        $this->assertSame(['Assembly debate', 'Flood in Fontem'], $this->titles($this->retrieving(['filter' => ['category' => 'news', 'region' => 'fontem']])->get()));
        $this->assertSame(['Derby day', 'Assembly debate', 'Election results'], $this->titles($this->retrieving(['filter' => ['category' => 'politics,sport']])->get()));
        $this->assertSame(['Assembly debate', 'Flood in Fontem'], $this->titles($this->retrieving(['filter' => ['tag' => 'flood']])->get()), 'renamed filter');
        $this->assertSame(['Assembly debate'], $this->titles($this->retrieving(['filter' => ['search' => 'flood debate']])->get()));
        $this->assertSame(0, $this->retrieving(['filter' => ['year' => now()->year + 1]])->count(), 'closure filter');
        $this->assertSame(4, $this->retrieving(['filter' => ['category' => '']])->count(), 'empty values are skipped');
    }

    public function test_search_sort_include_and_page_size(): void
    {
        $this->newsroom();

        $this->assertSame(['Assembly debate', 'Flood in Fontem'], $this->titles($this->retrieving(['q' => 'flood'])->get()));
        $this->assertSame(['Assembly debate', 'Derby day', 'Election results', 'Flood in Fontem'], $this->titles($this->retrieving(['sort' => 'title'])->get()));
        $this->assertSame('Election results', $this->retrieving(['sort' => '-views,title'])->first()->title);

        $article = $this->retrieving(['include' => 'tags,author.user'])->first();
        $this->assertTrue($article->relationLoaded('tags'));
        $this->assertTrue($article->author->relationLoaded('user'));

        $this->assertSame(2, $this->retrieving(['per_page' => '2'])->paginate()->perPage());
        $this->app['config']->set('retrive.max_per_page', 3);
        $this->app->forgetInstance(RetrievalManager::class);
        Retrive::clearResolvedInstances();
        $this->assertSame(3, $this->retrieving(['per_page' => '500'])->paginate()->perPage(), 'capped');
    }

    public function test_requested_sort_replaces_the_coded_one(): void
    {
        $this->newsroom();

        $retriever = Retrive::articles()->oldest()->fromRequest(Request::create('/', 'GET', ['sort' => '-views']));

        $this->assertSame('Election results', $retriever->first()->title);
    }

    public static function disallowed(): array
    {
        return [
            'unknown filter' => [['filter' => ['author_id' => 1]]],
            'unknown sort' => [['sort' => 'password']],
            'unknown include' => [['include' => 'region.secret']],
            'malformed filter' => [['filter' => 'category']],
            'malformed per page' => [['per_page' => 'ten']],
            'nested filter' => [['filter' => ['category' => ['a' => 'b']]]],
        ];
    }

    #[DataProvider('disallowed')]
    public function test_strict_mode_refuses_with_400(array $query): void
    {
        try {
            $this->retrieving($query);
            $this->fail('Expected a 400.');
        } catch (InvalidQuery $exception) {
            $this->assertSame(400, $exception->getStatusCode());
        }
    }

    public function test_lenient_mode_ignores(): void
    {
        $this->newsroom();
        $this->app['config']->set('retrive.request.strict', false);
        $this->app->forgetInstance(RetrievalManager::class);
        Retrive::clearResolvedInstances();

        $this->assertSame(4, $this->retrieving(['filter' => ['author_id' => 1], 'sort' => 'password', 'include' => 'nope'])->count());
    }
}
