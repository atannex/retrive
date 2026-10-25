<?php

declare(strict_types=1);

namespace Atannex\Retrive\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

/**
 *     php artisan make:retrive-resource PostResource --model="App\Models\Posts\Post"
 */
final class MakeResourceCommand extends GeneratorCommand
{
    protected $name = 'make:retrive-resource';

    protected $description = 'Create a retrieval resource class';

    protected $type = 'Retrieval resource';

    protected function getStub(): string
    {
        $published = $this->laravel->basePath('stubs/retrive/resource.stub');

        return file_exists($published) ? $published : __DIR__.'/../../stubs/resource.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Retrieval';
    }

    protected function buildClass($name): string
    {
        $model = (string) ($this->option('model') ?: $this->guessModel($name));
        $model = ltrim(str_replace('/', '\\', $model), '\\');

        if (! str_contains($model, '\\')) {
            $model = $this->rootNamespace().'Models\\'.$model;
        }

        return str_replace(
            ['{{ model }}', '{{ modelClass }}'],
            [$model, class_basename($model)],
            parent::buildClass($name),
        );
    }

    private function guessModel(string $name): string
    {
        return Str::beforeLast(class_basename($name), 'Resource') ?: 'Model';
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function getOptions(): array
    {
        return [
            ['model', 'm', InputOption::VALUE_REQUIRED, 'The Eloquent model the resource retrieves'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite an existing class'],
        ];
    }
}
