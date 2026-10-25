<?php

declare(strict_types=1);

namespace Atannex\Retrive\Contracts;

use Atannex\Retrive\Retriever;

/**
 * A reusable, named piece of a retrieval:
 *
 *     final class EditorsPicks implements Criterion
 *     {
 *         public function apply(Retriever $retriever): void
 *         {
 *             $retriever->scope('activeEditorPick')->latest('editor_pick_at');
 *         }
 *     }
 *
 *     Retrive::posts()->apply(new EditorsPicks)->get(4);
 */
interface Criterion
{
    public function apply(Retriever $retriever): void;
}
