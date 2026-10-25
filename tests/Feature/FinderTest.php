<?php

namespace Atannex\Retrive\Tests\Feature;

use Atannex\Retrive\Exceptions\UnknownFinder;
use Atannex\Retrive\Facades\Retrive;
use Atannex\Retrive\Retriever;
use Atannex\Retrive\Tests\Fixtures\Article;
use Atannex\Retrive\Tests\TestCase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class FinderTest extends TestCase
{
    public function test_single_record_finders(): void
    {
        $n = $this->newsroom();

        $this->assertSame('Flood in Fontem', Retrive::getArticleBySlug('flood-in-fontem')->title);
        $this->assertSame('Election results', Retrive::getArticleById($n['a2']->id)->title);
        $this->assertSame('Flood in Fontem', Retrive::getArticleByPath('news/flood-in-fontem')->title, 'alias');
        $this->assertSame('Flood in Fontem', Retrive::findArticleByTitle('Flood in Fontem')->title, 'plain column');
        $this->assertSame('Derby day', Retrive::getArticle()->title, 'newest by default');
        $this->assertSame('Derby day', Retrive::latestArticle()->title);
        $this->assertSame('Flood in Fontem', Retrive::oldestArticle()->title);
    }

    public function test_hidden_records_are_never_found(): void
    {
        $n = $this->newsroom();

        $this->assertNull(Retrive::getArticleBySlug('draft-budget'));
        $this->assertNull(Retrive::getArticleById($n['a6']->id), 'scheduled');
        $this->assertFalse(Retrive::existsArticleBySlug('draft-budget'));
        $this->assertTrue(Retrive::existsArticleBySlug('derby-day'));
    }

    public function test_or_fail_throws_a_404_model_not_found(): void
    {
        $this->newsroom();

        $this->expectException(ModelNotFoundException::class);
        Retrive::getArticleBySlugOrFail('nope');
    }

    public function test_plural_finders_by_relation_accept_models_keys_and_slugs(): void
    {
        $n = $this->newsroom();

        // Newest first; politics and elections are below news.
        $expected = ['Assembly debate', 'Election results', 'Flood in Fontem'];

        $this->assertSame($expected, $this->titles(Retrive::getArticlesByCategory('news')));
        $this->assertSame($expected, $this->titles(Retrive::getArticlesByCategory($n['news'])));
        $this->assertSame($expected, $this->titles(Retrive::getArticlesByCategory($n['news']->id)));
        $this->assertSame($expected, $this->titles(Retrive::getArticlesBycategory('news')), 'field case is ignored');

        $this->assertSame(['Assembly debate', 'Flood in Fontem'], $this->titles(Retrive::getArticlesByTag('flood')), 'many-to-many, singular field');
        $this->assertSame(['Assembly debate', 'Flood in Fontem'], $this->titles(Retrive::getArticlesByAuthor('ada')), 'slug through a relation (user.slug)');
        $this->assertSame(['Derby day', 'Election results'], $this->titles(Retrive::getArticlesByRegion(['alou'])));
        $this->assertSame(['Derby day', 'Election results'], $this->titles(Retrive::getArticlesByRegion('alou,unknown')), 'comma lists');
        $this->assertSame([], $this->titles(Retrive::getArticlesByCategory('unknown')));
    }

    public function test_singular_finder_returns_the_newest_match(): void
    {
        $this->newsroom();

        $this->assertSame('Assembly debate', Retrive::getArticleByCategory('news')->title);
    }

    public function test_combined_fields_limits_and_closures(): void
    {
        $this->newsroom();

        $this->assertSame(['Assembly debate', 'Flood in Fontem'], $this->titles(Retrive::getArticlesByCategoryAndRegion('news', 'fontem')));
        $this->assertSame(['Assembly debate'], $this->titles(Retrive::getArticlesByCategory('news', 1)));
        $this->assertSame(['Flood in Fontem'], $this->titles(Retrive::getArticlesByCategory('news', fn (Retriever $r) => $r->oldest()->limit(1))));
        $this->assertSame(['Derby day'], $this->titles(Retrive::getArticles(fn (Retriever $r) => $r->breaking())));
    }

    public function test_counts_pages_and_random(): void
    {
        $this->newsroom();

        $this->assertSame(4, Retrive::countArticles());
        $this->assertSame(2, Retrive::countArticlesByTag('politics'));

        $page = Retrive::paginateArticlesByRegion('fontem', 1);
        $this->assertInstanceOf(LengthAwarePaginator::class, $page);
        $this->assertSame(2, $page->total());
        $this->assertSame(['Assembly debate'], $this->titles($page->items()));

        $this->assertCount(2, Retrive::randomArticles(2));
        $this->assertNotNull(Retrive::randomArticle());
    }

    public function test_resources_named_by_key_class_and_model_trait(): void
    {
        $this->newsroom();

        $this->assertSame(4, Retrive::articles()->count());
        $this->assertSame(4, Retrive::for(Article::class)->count());
        $this->assertSame(4, Article::retrieve()->count());
        $this->assertSame('Politics', Retrive::getCategoryBySlug('politics')->name, 'plain model resource');
        $this->assertSame(['Elections'], Retrive::getCategoriesByParent('politics')->pluck('name')->all());
    }

    public function test_unknown_finders_explain_themselves(): void
    {
        $this->expectException(UnknownFinder::class);
        $this->expectExceptionMessageMatches('/getPostBySlug/');

        Retrive::fetchWidgets();
    }

    public function test_missing_values_are_reported(): void
    {
        $this->expectException(UnknownFinder::class);

        Retrive::getArticlesByCategoryAndRegion('news');
    }
}
