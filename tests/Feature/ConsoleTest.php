<?php

namespace Atannex\Retrive\Tests\Feature;

use Atannex\Retrive\Tests\TestCase;
use Illuminate\Support\Facades\File;

class ConsoleTest extends TestCase
{
    public function test_list(): void
    {
        $this->artisan('retrive:list')->expectsOutputToContain('articles')->assertSuccessful();
        $this->artisan('retrive:list', ['resource' => 'articles'])->expectsOutputToContain('getArticleBySlug')->assertSuccessful();
    }

    public function test_sql(): void
    {
        $this->newsroom();

        $this->artisan('retrive:sql', ['finder' => 'getArticlesByRegion', 'values' => ['1']])
            ->expectsOutputToContain('"region_id" in (1)')
            ->assertSuccessful();

        $this->artisan('retrive:sql', ['finder' => 'countArticlesByTag', 'values' => ['flood'], '--run' => true])
            ->expectsOutputToContain('2')
            ->assertSuccessful();

        $this->artisan('retrive:sql', ['finder' => 'nope'])->assertFailed();
    }

    public function test_make_resource(): void
    {
        $path = app_path('Retrieval/PostResource.php');
        File::delete($path);

        $this->artisan('make:retrive-resource', ['name' => 'PostResource', '--model' => 'App\\Models\\Posts\\Post'])->assertSuccessful();

        $code = File::get($path);
        $this->assertStringContainsString('namespace App\\Retrieval;', $code);
        $this->assertStringContainsString('use App\\Models\\Posts\\Post;', $code);
        $this->assertStringContainsString('protected string $model = Post::class;', $code);

        File::deleteDirectory(app_path('Retrieval'));
    }
}
