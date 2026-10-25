<?php

declare(strict_types=1);

namespace Atannex\Retrive\Support;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Traversable;

/**
 * Normalises the values a finder or filter receives.
 */
final class Values
{
    /**
     * Flatten a value, a list, a collection or a comma-separated string
     * into a list. Models stay whole.
     *
     * @return array<int, mixed>
     */
    public static function list(mixed $value, bool $splitCommas = false): array
    {
        if ($value instanceof Model) {
            return [$value];
        }

        if ($value instanceof Arrayable) {
            $value = $value instanceof Traversable ? iterator_to_array($value, false) : $value->toArray();
        } elseif ($value instanceof Traversable) {
            $value = iterator_to_array($value, false);
        }

        if (is_string($value) && $splitCommas && str_contains($value, ',')) {
            $value = explode(',', $value);
        }

        $items = [];

        foreach ((array) $value as $item) {
            if (is_array($item) || $item instanceof Traversable) {
                array_push($items, ...self::list($item, $splitCommas));
            } elseif (is_string($item)) {
                if (($item = trim($item)) !== '') {
                    $items[] = $item;
                }
            } elseif ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Whether a value names a record by key rather than by slug.
     */
    public static function isKey(mixed $value, Model $model): bool
    {
        if (is_int($value)) {
            return true;
        }

        if (! is_string($value)) {
            return false;
        }

        return $model->getKeyType() === 'int' || $model->getIncrementing()
            ? ctype_digit($value)
            : Str::isUuid($value) || Str::isUlid($value);
    }

    /**
     * "CategoryAndRegion" => ['category', 'region']. Splits on "And" only
     * before a capital, so "Brand" or "Android" stay whole.
     *
     * @return array<int, string>
     */
    public static function fields(string $fields): array
    {
        return array_values(array_map(
            fn (string $field) => lcfirst($field),
            array_filter(preg_split('/And(?=[A-Z])/', ucfirst($fields)) ?: [])
        ));
    }
}
