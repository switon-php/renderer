<?php

declare(strict_types=1);

namespace Switon\Rendering\Engine\Sword;

/**
 * Runtime loop metadata object for Sword <code>@foreach</code> directives.
 *
 * Use when template code needs index, first/last flags, nesting depth,
 * or parent loop metadata via the injected <code>$loop</code> variable.
 *
 * @see \Switon\Rendering\Engine\Sword\Compiler
 * @see \Switon\Rendering\Engine\Sword
 */
class Loop
{
    /** 0-based index of the current iteration */
    public int $index = -1;

    /** 1-based iteration count */
    public int $iteration = 0;

    /** Remaining iterations (null if items are not countable) */
    public ?int $remaining = null;

    /** Total item count (null if items are not countable) */
    public ?int $count;

    /** Whether this is the first iteration */
    public bool $first = false;

    /** Whether this is the last iteration */
    public bool $last = false;

    /** Whether the current 1-based iteration is even */
    public bool $even = false;

    /** Whether the current 1-based iteration is odd */
    public bool $odd = true;

    /** Nesting depth (1 = outermost <code>@foreach</code>) */
    public int $depth;

    /** Parent loop reference for nested <code>@foreach</code> */
    public ?self $parent;

    public function __construct(mixed $items, ?self $parent = null)
    {
        $this->count = is_countable($items) ? count($items) : null;
        $this->depth = ($parent !== null ? $parent->depth : 0) + 1;
        $this->parent = $parent;
    }

    /**
     * Advance the loop to the next iteration.
     *
     * Called by compiled Sword output at each iteration step.
     */
    public function step(): void
    {
        $this->index++;
        $this->iteration = $this->index + 1;
        $this->first = $this->index === 0;
        $this->last = $this->count !== null && $this->iteration === $this->count;
        $this->even = $this->iteration % 2 === 0;
        $this->odd = !$this->even;
        $this->remaining = $this->count !== null ? $this->count - $this->iteration : null;
    }
}
