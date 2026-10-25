<?php

declare(strict_types=1);

namespace Atannex\Retrive\Events;

/**
 * Dispatched after a retrieval when config retrive.events is on.
 */
final class Retrieved
{
    public function __construct(
        public readonly string $resource,
        public readonly string $operation,
        public readonly float $milliseconds,
        public readonly bool $fromCache,
        public readonly ?string $sql = null,
    ) {}
}
