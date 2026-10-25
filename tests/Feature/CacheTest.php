<?php

namespace Atannex\Retrive\Tests\Feature;

use Atannex\Retrive\Events\Retrieved;
use Atannex\Retrive\Facades\Retrive;
use Atannex\Retrive\Tests\Fixtures\Article;
use Atannex\Retrive\Tests\Fixtures\Tag;
use Atannex\Retrive\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class CacheTest extends TestCase
{
    private function queries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_cached_retrievals_skip_the_database(): void
    {
        $this->newsroom();

        $this->assertSame(1, $this->queries(fn () => Retrive::articles()->cache()->byRegion(1)->get()));
        $this->assertSame(0, $this->queries(fn () => Retrive::articles()->cache()->byRegion(1)->get()));
        $this->assertSame(1, $this->queries(fn () => Retrive::articles()->cache()->byRegion(2)->get()), 'other bindings, other key');
        $this->assertSame(1, $this->queries(fn () => Retrive::articles()->cache()->byRegion(1)->count()), 'other operation, other key');
        $this->assertSame(1, $this->queries(fn () => Retrive::articles()->byRegion(1)->get()), 'not cached unless asked');
    }

    public function test_keys_survive_the_moving_now_of_the_visibility_rule(): void
    {
        $this->newsroom();

        Retrive::articles()->cache()->get();
        $this->travel(5)->seconds();

        $this->assertSame(0, $this->queries(fn () => Retrive::articles()->cache()->get()));
    }

    public function test_writes_invalidate(): void
    {
        $n = $this->newsroom();
        $cached = fn () => $this->titles(Retrive::articles()->cache()->get());

        $this->assertCount(4, $cached());

        $n['a1']->update(['title' => 'Flood in Fontem (update)']);
        $this->assertContains('Flood in Fontem (update)', $cached(), 'saved');

        $n['a1']->delete();
        $this->assertCount(3, $cached(), 'deleted');

        $n['a1']->restore();
        $this->assertCount(4, $cached(), 'restored');

        Tag::query()->first()->update(['name' => 'Floods']);
        $this->assertSame(1, $this->queries($cached), 'a dependency invalidates too');
    }

    public function test_manual_flush_and_fresh(): void
    {
        $this->newsroom();
        Retrive::articles()->cache()->count();

        Article::withoutEvents(fn () => Article::query()->whereKey(1)->update(['published_at' => null]));
        $this->assertSame(4, Retrive::articles()->cache()->count(), 'quiet writes are not seen…');
        $this->assertSame(3, Retrive::articles()->cache()->fresh()->count(), '…unless fresh');

        Retrive::flush('articles');
        $this->assertSame(3, Retrive::articles()->cache()->count(), '…or flushed');

        $this->artisan('retrive:clear')->assertSuccessful();
    }

    public function test_cached_pages_are_rebuilt_for_the_current_request(): void
    {
        $this->newsroom();

        $first = Retrive::articles()->cache()->paginate(2, 1);
        $this->assertSame(0, $this->queries(fn () => Retrive::articles()->cache()->paginate(2, 1)));
        $this->assertSame(4, $first->total());
        $this->assertSame(['Derby day', 'Assembly debate'], $this->titles($first->items()));
    }

    public function test_resource_ttl_and_disabled_cache(): void
    {
        $this->newsroom();
        $this->app['config']->set('retrive.cache.enabled', false);

        $this->assertSame(1, $this->queries(fn () => Retrive::articles()->cache()->count()));
        $this->assertSame(1, $this->queries(fn () => Retrive::articles()->cache()->count()));
    }

    public function test_events(): void
    {
        $this->newsroom();
        $this->app['config']->set('retrive.events', true);
        Event::fake([Retrieved::class]);

        Retrive::articles()->cache()->count();
        Retrive::articles()->cache()->count();

        Event::assertDispatchedTimes(Retrieved::class, 2);
        Event::assertDispatched(Retrieved::class, fn (Retrieved $e) => $e->resource === 'articles' && $e->operation === 'count' && $e->fromCache);
    }
}
