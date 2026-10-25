<?php

declare(strict_types=1);

namespace Atannex\Retrive\Exceptions;

use LogicException;

/**
 * A resource definition is wrong: a programming error, never a request one.
 */
final class InvalidResource extends LogicException implements RetriveException
{
    public static function missingModel(string $resource): self
    {
        return new self("Resource [{$resource}] does not declare its \$model.");
    }

    public static function notAModel(string $resource, string $model): self
    {
        return new self("Resource [{$resource}] points at [{$model}], which is not an Eloquent model.");
    }

    public static function notAResource(string $class): self
    {
        return new self("[{$class}] is neither a Resource nor an Eloquent model.");
    }

    public static function notRankable(string $key): self
    {
        return new self("Resource [{$key}] has no popularity order; override Resource::popular().");
    }

    public static function unknownPreset(string $key, string $preset): self
    {
        return new self("Resource [{$key}] has no preset [{$preset}].");
    }

    public static function notSearchable(string $key): self
    {
        return new self("Resource [{$key}] has no searchable columns; set Resource::\$searchable or pass columns to search().");
    }

    public static function undated(string $key): self
    {
        return new self("Resource [{$key}] has no date column; set Resource::\$date.");
    }

    public static function unknownField(string $key, string $field): self
    {
        return new self("Resource [{$key}] cannot filter on [{$field}]: it is neither an alias, a relation nor a column name.");
    }
}
