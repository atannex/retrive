<?php

namespace Atannex\Retrive\Tests\Unit;

use Atannex\Retrive\Finders\FinderParser;
use Atannex\Retrive\GenericResource;
use Atannex\Retrive\Support\Search;
use Atannex\Retrive\Support\Values;
use Atannex\Retrive\Tests\Fixtures\Article;
use Atannex\Retrive\Tests\Fixtures\Region;
use Atannex\Retrive\Tests\TestCase;

class FinderParserTest extends TestCase
{
    private function parser(): FinderParser
    {
        $resources = [
            new GenericResource(Article::class, 'posts'),
            new GenericResource(Region::class, 'bylaws'),
            new GenericResource(Region::class, 'news'),
        ];

        return new FinderParser(function (string $token) use ($resources) {
            foreach ($resources as $resource) {
                if (strtolower($token) === strtolower($resource->pluralName())) {
                    return [$resource, true];
                }
                if (strtolower($token) === strtolower($resource->singularName())) {
                    return [$resource, false];
                }
            }

            return null;
        });
    }

    public function test_names(): void
    {
        $parse = fn (string $method) => ($f = $this->parser()->parse($method))
            ? [$f->operation, $f->resource->key(), $f->plural, $f->fields, $f->orFail]
            : null;

        $this->assertSame(['get', 'posts', false, ['slug'], false], $parse('getPostBySlug'));
        $this->assertSame(['get', 'posts', false, ['id'], true], $parse('getPostByIdOrFail'));
        $this->assertSame(['get', 'posts', true, ['category'], false], $parse('getPostsByCategory'));
        $this->assertSame(['get', 'posts', false, ['category'], false], $parse('getPostBycategory'));
        $this->assertSame(['count', 'posts', true, ['category', 'region'], false], $parse('countPostsByCategoryAndRegion'));
        $this->assertSame(['get', 'posts', true, ['brand'], false], $parse('getPostsByBrand'));
        $this->assertSame(['latest', 'posts', true, [], false], $parse('latestPosts'));
        $this->assertSame(['get', 'bylaws', true, ['year'], false], $parse('getBylawsByYear'), '"By" inside the resource name');
        $this->assertSame(['paginate', 'news', true, ['region'], false], $parse('paginateNewsByRegion'), 'uncountable reads as plural');

        $this->assertNull($parse('getWidgets'));
        $this->assertNull($parse('getPostBy'));
        $this->assertNull($parse('deletePostBySlug'));
        $this->assertNull($parse('get'));
    }

    public function test_values_and_search_words(): void
    {
        $this->assertSame(['category', 'region'], Values::fields('CategoryAndRegion'));
        $this->assertSame(['android'], Values::fields('Android'));
        $this->assertSame(['a', 'b', 'c'], Values::list(['a', ' b ', ['c', null, '']]));
        $this->assertSame(['a', 'b'], Values::list('a, b', splitCommas: true));

        $this->assertSame(['flood', 'heavy rain', 'fontem'], Search::words('flood "heavy rain" fontem flood'));
        $this->assertSame('50!% !_!!', Search::escape('50% _!'));
        $this->assertCount(Search::MAX_WORDS, Search::words(implode(' ', range(1, 30))));
    }
}
