<?php

declare(strict_types=1);

namespace Atannex\Retrive;

use Illuminate\Database\Eloquent\Model;

/**
 * The resource a plain model gets when it is registered (or retrieved)
 * without a Resource class of its own: conventions only, everything visible.
 */
final class GenericResource extends Resource
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(string $model, ?string $key = null)
    {
        $this->model = $model;
        $this->key = $key;
    }
}
