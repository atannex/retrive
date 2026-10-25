<?php

declare(strict_types=1);

namespace Atannex\Retrive\Console;

use Atannex\Retrive\Resource;
use Atannex\Retrive\RetrievalManager;
use Illuminate\Console\Command;

final class ListCommand extends Command
{
    protected $signature = 'retrive:list {resource? : Show one resource in detail}';

    protected $description = 'List retrieval resources, their filters and example finders';

    public function handle(RetrievalManager $manager): int
    {
        if ($name = $this->argument('resource')) {
            $this->detail($manager->resource($name));

            return self::SUCCESS;
        }

        $resources = $manager->resources();

        if ($resources === []) {
            $this->components->warn('No resources registered. Add them to config/retrive.php.');

            return self::SUCCESS;
        }

        $this->table(
            ['Key', 'Model', 'Slug', 'Date', 'Visibility', 'Cache', 'Filters'],
            array_map(fn (Resource $resource) => [
                $resource->key(),
                $resource->model(),
                $resource->slugColumn(),
                $resource->dateColumn() ?? '—',
                $this->hasVisibility($resource) ? 'yes' : 'all',
                $resource->ttl() !== null ? $resource->ttl().'s' : 'on demand',
                implode(', ', array_keys($resource->filters())) ?: '—',
            ], array_values($resources)),
        );

        return self::SUCCESS;
    }

    private function detail(Resource $resource): void
    {
        $singular = $resource->singularName();
        $plural = $resource->pluralName();

        $this->components->twoColumnDetail('<fg=green>'.$resource->key().'</>', $resource::class);
        $this->components->twoColumnDetail('Model', $resource->model());
        $this->components->twoColumnDetail('Slug column', $resource->slugColumn());
        $this->components->twoColumnDetail('Date column', $resource->dateColumn() ?? '—');
        $this->components->twoColumnDetail('Visibility rule', $this->hasVisibility($resource) ? 'yes' : 'none (everything visible)');
        $this->components->twoColumnDetail('Default sort', implode(', ', $resource->defaultSort()));
        $this->components->twoColumnDetail('Presets', implode(', ', array_keys($resource->presets())) ?: '—');
        $this->components->twoColumnDetail('Searchable', implode(', ', $resource->searchable()) ?: '—');
        $this->components->twoColumnDetail('Filters', implode(', ', array_keys($resource->filters())) ?: '—');
        $this->components->twoColumnDetail('Sorts', implode(', ', array_keys($resource->sorts())) ?: '—');
        $this->components->twoColumnDetail('Includes', implode(', ', $resource->includes()) ?: '—');
        $this->components->twoColumnDetail('Trees', collect($resource->hierarchies())->map(fn ($column, $relation) => "{$relation} ({$column})")->implode(', ') ?: '—');
        $this->components->twoColumnDetail('Depends on', implode(', ', $resource->dependsOn()) ?: '—');
        $this->components->twoColumnDetail('Cache', $resource->ttl() !== null ? $resource->ttl().'s' : 'on demand');

        $this->newLine();
        $this->components->info('Example finders');

        foreach (["get{$singular}BySlug", "get{$singular}ById", "get{$plural}(10)", "latest{$plural}(5)", "count{$plural}", "paginate{$plural}(15)"] as $example) {
            $this->line("  Retrive::{$example}");
        }

        foreach (array_keys($resource->aliases()) as $alias) {
            $this->line('  Retrive::get'.$singular.'By'.ucfirst($alias).'($value)');
        }
    }

    private function hasVisibility(Resource $resource): bool
    {
        return (new \ReflectionMethod($resource, 'visible'))->getDeclaringClass()->getName() !== Resource::class;
    }
}
