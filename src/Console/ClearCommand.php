<?php

declare(strict_types=1);

namespace Atannex\Retrive\Console;

use Atannex\Retrive\RetrievalManager;
use Illuminate\Console\Command;

final class ClearCommand extends Command
{
    protected $signature = 'retrive:clear {resource? : Only this resource}';

    protected $description = 'Invalidate cached retrievals';

    public function handle(RetrievalManager $manager): int
    {
        $resource = $this->argument('resource');

        $manager->flush($resource);

        $this->components->info($resource ? "Cached [{$resource}] retrievals invalidated." : 'All cached retrievals invalidated.');

        return self::SUCCESS;
    }
}
