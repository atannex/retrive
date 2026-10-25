<?php

declare(strict_types=1);

namespace Atannex\Retrive\Console;

use Atannex\Retrive\RetrievalManager;
use Illuminate\Console\Command;

/**
 *     php artisan retrive:sql getPostsByCategory news
 *     php artisan retrive:sql countPostsByTagAndRegion flood fontem --run
 */
final class SqlCommand extends Command
{
    protected $signature = 'retrive:sql
        {finder : A finder name, e.g. getPostsByCategory}
        {values?* : One value per field, then an optional limit}
        {--run : Also run it and show the result}';

    protected $description = 'Show the SQL a magic finder runs';

    public function handle(RetrievalManager $manager): int
    {
        $method = (string) $this->argument('finder');
        $finder = $manager->finder($method);

        if ($finder === null) {
            $this->components->error("[{$method}] is not a finder (registered: ".implode(', ', array_keys($manager->resources())).').');

            return self::FAILURE;
        }

        $values = (array) $this->argument('values');

        $this->components->twoColumnDetail('Resource', $finder->resource->key());
        $this->components->twoColumnDetail('Operation', $finder->operation.($finder->plural ? ' (many)' : ' (one)'));
        $this->components->twoColumnDetail('Fields', implode(', ', $finder->fields) ?: '—');

        [$retriever] = $manager->prepare($finder, $values);

        $this->newLine();
        $this->line($retriever->toRawSql());

        if ($this->option('run')) {
            $result = $manager->callFinder($finder, $values);

            $this->newLine();
            $this->line(json_encode(
                is_object($result) && method_exists($result, 'toArray') ? $result->toArray() : $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) ?: '');
        }

        return self::SUCCESS;
    }
}
