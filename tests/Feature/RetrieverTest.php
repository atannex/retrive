<?php

namespace Atannex\Retrive\Tests\Feature;

use Atannex\Retrive\Contracts\Criterion;
use Atannex\Retrive\Exceptions\InvalidResource;
use Atannex\Retrive\Facades\Retrive;
use Atannex\Retrive\Retriever;
use Atannex\Retrive\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;

class RetrieverTest extends TestCase
{
    public function test_lookups(): void
    {
        $n = $this->newsroom();
        $articles = Retrive::articles();

        $this->assertSame('Flood in Fontem', $articles->find($n['a1']->id)->title);
        $this->assertSame('Flood in Fontem', $articles->find((string) $n['a1']->id)->title);
        $this->assertNull($articles->find($n['a5']->id));
        $this->assertNull($articles->find(null));
        $this->assertSame('Derby day', $articles->findBySlug(' derby-day ')->title);
        $this->assertNull($articles->findBySlug(''));
        $this->assertSame('Derby day', $articles->findBy('slug', 'derby-day')->title);
        $this->assertSame('Ben', Retrive::authors()->findBySlug('ben')->user->name);

        $this->assertSame(
            ['Derby day', 'Flood in Fontem'],
            $this->titles($articles->findMany([$n['a4']->id, $n['a5']->id, 999, $n['a1']->id])),
            'input order, hidden and unknown skipped',
        );
    }

    public function test_declared_sorts_apply_to_any_query(): void
    {
        $this->newsroom();
        $resource = Retrive::articles()->resource();

        $this->assertSame(['Election results', 'Assembly debate', 'Derby day', 'Flood in Fontem'], $this->titles(Retrive::articles()->unordered()->sort('-views')->get()));

        $query = Retrive::articles()->query(ordered: false);
        $this->assertTrue($resource->applySort($query, 'title', 'DESC'));
        $this->assertSame(['Flood in Fontem', 'Election results', 'Derby day', 'Assembly debate'], $query->pluck('title')->all());

        $untouched = Retrive::articles()->query(ordered: false);
        $this->assertFalse($resource->applySort($untouched, 'body', 'asc'), 'not a declared sort');
        $this->assertSame([], $untouched->getQuery()->orders ?? []);
    }

    public function test_a_retriever_can_run_several_terminals(): void
    {
        $this->newsroom();
        $retriever = Retrive::articles()->byRegion('fontem');

        $this->assertSame(2, $retriever->count());
        $this->assertSame(['Assembly debate', 'Flood in Fontem'], $this->titles($retriever->get()));
        $this->assertSame('Assembly debate', $retriever->first()->title);
    }

    public function test_with_hidden_and_trashed(): void
    {
        $n = $this->newsroom();
        $n['a1']->delete();

        $this->assertSame(3, Retrive::articles()->count());
        $this->assertSame(5, Retrive::articles()->withHidden()->count());
        $this->assertSame(4, Retrive::articles()->withTrashed()->count());
        $this->assertSame(['Flood in Fontem'], $this->titles(Retrive::articles()->onlyTrashed()->get()));
    }

    public function test_descendants_can_be_left_out(): void
    {
        $this->newsroom();

        $this->assertSame(['Flood in Fontem'], $this->titles(Retrive::articles()->withoutDescendants()->byCategory('news')->get()));
        $this->assertSame(['Flood in Fontem'], $this->titles(Retrive::articles()->belongingTo('category', 'news', descendants: false)->get()));
    }

    public function test_search(): void
    {
        $this->newsroom();
        $search = fn (string $term) => $this->titles(Retrive::articles()->search($term)->get());

        $this->assertSame(['Assembly debate', 'Flood in Fontem'], $search('flood'), 'title, body or tag');
        $this->assertSame(['Assembly debate'], $search('flood debate'), 'every word must match');
        $this->assertSame(['Assembly debate'], $search('"flood response"'), 'phrases');
        $this->assertSame(['Flood in Fontem'], $search('50%'), 'wildcards are literal');
        $this->assertSame([], $search('5_%'));
        $this->assertSame(4, Retrive::articles()->search('  ')->count(), 'blank term: no filter');
        $this->assertSame(['Election results'], $this->titles(Retrive::articles()->search('results', ['title'])->get()));
    }

    public function test_search_needs_columns(): void
    {
        $this->expectException(InvalidResource::class);

        Retrive::tags()->search('fontem');
    }

    public function test_dates(): void
    {
        $this->newsroom();

        $this->assertSame(['Derby day', 'Assembly debate'], $this->titles(Retrive::articles()->recent(3)->get()));
        $this->assertSame(['Election results'], $this->titles(Retrive::articles()->between(now()->subDays(6), now()->subDays(4))->get()));
        $this->assertSame(2, Retrive::articles()->since(now()->subDays(3))->count());
        $this->assertSame(2, Retrive::articles()->until(now()->subDays(4))->count());
        $this->assertSame(4, Retrive::articles()->inYear(now()->year)->count() + Retrive::articles()->inYear(now()->year - 1)->count());
        $this->assertSame(1, Retrive::articles()->onDay(now()->subDay())->count());
        $this->assertSame(
            Retrive::articles()->inMonth(now()->year, now()->month)->count(),
            Retrive::articles()->dated(now()->year, now()->month)->count(),
        );
    }

    public function test_ordering(): void
    {
        $this->newsroom();

        $this->assertSame('Flood in Fontem', Retrive::articles()->oldest()->first()->title);
        $this->assertSame('Election results', Retrive::articles()->popular()->first()->title);
        $this->assertSame(['Assembly debate', 'Derby day', 'Election results', 'Flood in Fontem'], $this->titles(Retrive::articles()->sort('title')->get()));
        $this->assertSame('Flood in Fontem', Retrive::articles()->sort('-views')->unordered()->oldest()->first()->title);
    }

    public function test_scopes_criteria_macros_and_builder_methods(): void
    {
        $this->newsroom();

        $this->assertSame(['Derby day'], $this->titles(Retrive::articles()->breaking()->get()), 'model scope');
        $this->assertSame(['Derby day'], $this->titles(Retrive::articles()->scope('breaking')->get()));
        $this->assertSame(2, Retrive::articles()->where('views', '>', 100)->count(), 'builder method');

        $popular = new class implements Criterion
        {
            public function apply(Retriever $retriever): void
            {
                $retriever->where('views', '>=', 100);
            }
        };
        $this->assertSame(2, Retrive::articles()->apply($popular)->count());
        $this->assertSame(1, Retrive::articles()->apply(fn (Retriever $r) => $r->byRegion('alou'), $popular)->count());

        Retriever::macro('fromFontem', fn () => $this->byRegion('fontem'));
        $this->assertSame(2, Retrive::articles()->fromFontem()->count());

        $this->assertSame(1, Retrive::articles()->tap(fn (Builder $q) => $q->where('title', 'like', 'Derby%'))->count());
    }

    public function test_builder_terminals_cannot_skip_visibility(): void
    {
        $this->newsroom();

        $this->assertSame(4, Retrive::articles()->toBase()->count(), 'forwarded terminal runs with visibility');
    }

    public function test_neighbours(): void
    {
        $n = $this->newsroom();

        $this->assertSame('Flood in Fontem', Retrive::articles()->previous($n['a2'])->title);
        $this->assertSame('Assembly debate', Retrive::articles()->next($n['a2'])->title);
        $this->assertNull(Retrive::articles()->next($n['a4']), 'drafts and scheduled are not neighbours');
        $this->assertSame('Flood in Fontem', Retrive::articles()->previous($n['a3'], within: 'region_id')->title);
        $this->assertSame('Election results', Retrive::articles()->next($n['a1'], by: 'id')->title);
    }

    public function test_related(): void
    {
        $n = $this->newsroom();

        $this->assertSame(['Assembly debate'], $this->titles(Retrive::articles()->relatedTo($n['a1'])->get()));
        $this->assertSame(['Election results', 'Flood in Fontem'], $this->titles(Retrive::articles()->relatedTo($n['a3'])->get()), 'one shared tag each: newest first');

        $n['a1']->tags()->attach($n['politicsTag']->id);
        $this->assertSame(['Flood in Fontem', 'Election results'], $this->titles(Retrive::articles()->relatedTo($n['a3']->fresh())->get()), 'most shared tags first');
        $this->assertSame(['Flood in Fontem'], $this->titles(Retrive::articles()->relatedTo($n['a3'], sameAs: 'region_id')->get()));
        $this->assertSame(3, Retrive::articles()->relatedTo($n['a4'])->count(), 'no tags: everything else');
    }

    public function test_presets_and_projection_helpers(): void
    {
        $n = $this->newsroom();

        $article = Retrive::articles()->preset('card')->findBySlug('flood-in-fontem');
        $this->assertTrue($article->relationLoaded('category'));
        $this->assertTrue($article->relationLoaded('author'));
        $this->assertTrue($article->author->relationLoaded('user'));

        $loaded = Retrive::articles()->preset('card')->with('tags')->with('region', fn ($q) => $q->select('id', 'name'))->first();
        $this->assertTrue($loaded->relationLoaded('tags'), 'with() after a preset, no callback');
        $this->assertSame(['id', 'name'], array_keys($loaded->region->getAttributes()), 'with() and a constraint');

        $this->assertSame([$n['a4']->id, $n['a3']->id], Retrive::articles()->ids(2));
        $this->assertSame(550, Retrive::articles()->sum('views'));
        $this->assertSame(300, (int) Retrive::articles()->max('views'));
        $this->assertSame('Derby day', Retrive::articles()->value('title'));
        $this->assertSame(['Derby day'], Retrive::articles()->pluck('title')->take(1)->all());
        $this->assertEquals(
            [$n['news']->id => 1, $n['politics']->id => 1, $n['elections']->id => 1, $n['sport']->id => 1],
            Retrive::articles()->countBy('category_id')->all(),
        );
        $this->assertSame(2, Retrive::articles()->byRegion('alou')->countBy('region_id')->first());

        $this->expectException(InvalidResource::class);
        Retrive::articles()->preset('nope');
    }

    public function test_pagination_flavours_and_streaming(): void
    {
        $this->newsroom();

        $page = Retrive::articles()->paginate(3, 2);
        $this->assertSame(4, $page->total());
        $this->assertSame(['Flood in Fontem'], $this->titles($page->items()));

        $this->assertCount(2, Retrive::articles()->simplePaginate(2)->items());
        $this->assertCount(2, Retrive::articles()->oldest('id')->cursorPaginate(2)->items());
        $this->assertSame(4, Retrive::articles()->lazy(2)->count());

        $seen = 0;
        Retrive::articles()->chunk(3, function ($chunk) use (&$seen) {
            $seen += $chunk->count();
        });
        $this->assertSame(4, $seen);
    }

    public function test_sql_inspection(): void
    {
        $sql = Retrive::articles()->byRegion(1)->toRawSql();

        $this->assertStringContainsString('"region_id" in (1)', $sql);
        $this->assertStringContainsString('"published_at" is not null', $sql);
        $this->assertStringContainsString('order by "articles"."published_at" desc', $sql);
    }
}
