<?php

namespace Atannex\Retrive\Tests;

use Atannex\Retrive\Facades\Retrive;
use Atannex\Retrive\RetriveServiceProvider;
use Atannex\Retrive\Tests\Fixtures\Article;
use Atannex\Retrive\Tests\Fixtures\ArticleResource;
use Atannex\Retrive\Tests\Fixtures\Author;
use Atannex\Retrive\Tests\Fixtures\AuthorResource;
use Atannex\Retrive\Tests\Fixtures\Category;
use Atannex\Retrive\Tests\Fixtures\Region;
use Atannex\Retrive\Tests\Fixtures\Tag;
use Atannex\Retrive\Tests\Fixtures\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [RetriveServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Retrive' => Retrive::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('retrive.resources', [
            ArticleResource::class,
            AuthorResource::class,
            Category::class,
            Region::class,
            Tag::class,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('parent_id')->nullable();
        });

        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
        });

        Schema::create('authors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('slug_path')->nullable();
            $table->text('body')->nullable();
            $table->foreignId('category_id')->nullable();
            $table->foreignId('region_id')->nullable();
            $table->foreignId('author_id')->nullable();
            $table->boolean('is_breaking')->default(false);
            $table->unsignedInteger('views')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('article_tag', function (Blueprint $table) {
            $table->foreignId('article_id');
            $table->foreignId('tag_id');
        });
    }

    /**
     * A small newsroom:
     *
     *   news ── politics ── elections        fontem, alou
     *   sport
     *
     *   1 "Flood in Fontem"       news       fontem  tags flood, weather  10 days ago  views 50
     *   2 "Election results"      elections  alou    tags politics         5 days ago   views 300
     *   3 "Assembly debate"       politics   fontem  tags politics, flood  2 days ago   views 120
     *   4 "Derby day"             sport      alou    tags —                1 day ago    views 80, breaking
     *   5 "Draft: budget"         news       fontem  — unpublished
     *   6 "Scheduled: census"     news       alou    — in the future
     *
     * @return array<string, mixed>
     */
    protected function newsroom(): array
    {
        $news = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $politics = Category::query()->create(['name' => 'Politics', 'slug' => 'politics', 'parent_id' => $news->id]);
        $elections = Category::query()->create(['name' => 'Elections', 'slug' => 'elections', 'parent_id' => $politics->id]);
        $sport = Category::query()->create(['name' => 'Sport', 'slug' => 'sport']);

        $fontem = Region::query()->create(['name' => 'Fontem', 'slug' => 'fontem']);
        $alou = Region::query()->create(['name' => 'Alou', 'slug' => 'alou']);

        $flood = Tag::query()->create(['name' => 'Flood', 'slug' => 'flood']);
        $weather = Tag::query()->create(['name' => 'Weather', 'slug' => 'weather']);
        $politicsTag = Tag::query()->create(['name' => 'Politics', 'slug' => 'politics']);

        $ada = Author::query()->create(['user_id' => User::query()->create(['name' => 'Ada', 'slug' => 'ada'])->id]);
        $ben = Author::query()->create(['user_id' => User::query()->create(['name' => 'Ben', 'slug' => 'ben'])->id]);

        $article = fn (array $attributes) => Article::query()->create($attributes + ['slug_path' => null]);

        $a1 = $article(['title' => 'Flood in Fontem', 'slug' => 'flood-in-fontem', 'slug_path' => 'news/flood-in-fontem', 'body' => 'Rain 50% above normal', 'category_id' => $news->id, 'region_id' => $fontem->id, 'author_id' => $ada->id, 'views' => 50, 'published_at' => now()->subDays(10)]);
        $a2 = $article(['title' => 'Election results', 'slug' => 'election-results', 'category_id' => $elections->id, 'region_id' => $alou->id, 'author_id' => $ben->id, 'views' => 300, 'published_at' => now()->subDays(5)]);
        $a3 = $article(['title' => 'Assembly debate', 'slug' => 'assembly-debate', 'body' => 'Members discussed the flood response', 'category_id' => $politics->id, 'region_id' => $fontem->id, 'author_id' => $ada->id, 'views' => 120, 'published_at' => now()->subDays(2)]);
        $a4 = $article(['title' => 'Derby day', 'slug' => 'derby-day', 'category_id' => $sport->id, 'region_id' => $alou->id, 'author_id' => $ben->id, 'views' => 80, 'is_breaking' => true, 'published_at' => now()->subDay()]);
        $a5 = $article(['title' => 'Draft: budget', 'slug' => 'draft-budget', 'category_id' => $news->id, 'region_id' => $fontem->id, 'author_id' => $ada->id]);
        $a6 = $article(['title' => 'Scheduled: census', 'slug' => 'scheduled-census', 'category_id' => $news->id, 'region_id' => $alou->id, 'author_id' => $ben->id, 'published_at' => now()->addDay()]);

        $a1->tags()->attach([$flood->id, $weather->id]);
        $a2->tags()->attach([$politicsTag->id]);
        $a3->tags()->attach([$politicsTag->id, $flood->id]);

        return compact('news', 'politics', 'elections', 'sport', 'fontem', 'alou', 'flood', 'weather', 'politicsTag', 'ada', 'ben', 'a1', 'a2', 'a3', 'a4', 'a5', 'a6');
    }

    /**
     * @param  iterable<int, Model>  $models
     * @return array<int, string>
     */
    protected function titles(iterable $models): array
    {
        return collect($models)->pluck('title')->all();
    }
}
