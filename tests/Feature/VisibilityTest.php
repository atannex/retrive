<?php

namespace Atannex\Retrive\Tests\Feature;

use Atannex\Retrive\Facades\Retrive;
use Atannex\Retrive\Resource;
use Atannex\Retrive\Tests\Fixtures\Category;
use Atannex\Retrive\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * A resource's visibility rule reaches related records too: inside
 * whereHas / withCount / with closures, and when relation filters resolve
 * slugs and tree descendants.
 */
class VisibilityTest extends TestCase
{
    public function test_visible_callback_for_closures(): void
    {
        $n = $this->newsroom();

        $news = Category::query()->withCount(['articles' => Retrive::visible('articles')])->find($n['news']->id);
        $this->assertSame(1, $news->articles_count, 'draft and scheduled left out');

        $fontem = Retrive::visible('articles', fn (Builder $q) => $q->where('region_id', $n['fontem']->id));
        $this->assertSame(['Flood in Fontem'], $this->titles($n['news']->articles()->where($fontem)->get()));
    }

    public function test_where_has_and_count_visible(): void
    {
        $n = $this->newsroom();

        $counts = Retrive::authors()->withCountVisible('articles')->get()->pluck('articles_count', 'id')->sortKeys();
        $this->assertSame([$n['ada']->id => 2, $n['ben']->id => 2], $counts->all());

        $inFontem = Retrive::authors()
            ->withCountVisible(['articles as fontem_count' => fn (Builder $q) => $q->where('region_id', $n['fontem']->id)])
            ->get()->pluck('fontem_count', 'id')->sortKeys();
        $this->assertSame([$n['ada']->id => 2, $n['ben']->id => 0], $inFontem->all());

        $this->assertSame([$n['ben']->id], Retrive::authors()
            ->whereHasVisible('articles', fn (Builder $q) => $q->where('region_id', $n['alou']->id))
            ->ids());

        $this->assertSame([], Retrive::authors()->whereDoesntHaveVisible('articles')->ids());

        $ada = Retrive::authors()->withVisible('articles')->find($n['ada']->id);
        $this->assertSame(['Flood in Fontem', 'Assembly debate'], $this->titles($ada->articles));
    }

    public function test_relation_filters_skip_hidden_related_records(): void
    {
        $n = $this->newsroom();

        Retrive::register(new class extends Resource
        {
            protected string $model = Category::class;

            public function visible(Builder $query): void
            {
                $query->where('slug', '!=', 'politics');
            }
        }, 'categories');

        $this->assertSame(['Flood in Fontem'], $this->titles(Retrive::articles()->byCategory('news')->get()), 'a hidden node prunes its branch');
        $this->assertSame([], $this->titles(Retrive::articles()->byCategory('politics')->get()), 'a hidden slug matches nothing');
        $this->assertSame(
            ['Assembly debate', 'Election results'],
            $this->titles(Retrive::articles()->byCategory($n['politics'])->get()),
            'a model given directly is taken as it is',
        );
        $this->assertSame(
            ['Assembly debate', 'Election results', 'Flood in Fontem'],
            $this->titles(Retrive::articles()->withHidden()->byCategory('news')->published()->get()),
            'withHidden() first: hidden related records count',
        );
    }

    public function test_search_goes_through_the_model_search_scope(): void
    {
        $this->newsroom();

        $this->assertSame(['Fontem'], Retrive::regions()->search('ont')->pluck('name')->all());
        $this->assertSame(['Alou'], Retrive::regions()->search(Str::of('Alou'), (object) ['exact' => true])->pluck('name')->all());
        $this->assertSame([], Retrive::regions()->search('lou', (object) ['exact' => true])->pluck('name')->all());
        $this->assertSame(['Fontem'], Retrive::regions()->search('ont', ['name'])->pluck('name')->all(), 'columns force the plain search');
        $this->assertSame(2, Retrive::regions()->search(null)->count());
    }

    public function test_get_and_cursor_paginate_take_columns(): void
    {
        $this->newsroom();

        $article = Retrive::articles()->without('tags')->get(1, ['id', 'title'])->first();
        $this->assertSame(['id', 'title'], array_keys($article->getAttributes()));

        $page = Retrive::articles()->cursorPaginate(2, columns: ['id', 'published_at']);
        $this->assertSame(['id', 'published_at'], array_keys($page->first()->getAttributes()));
    }
}
