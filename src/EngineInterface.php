<?php

declare(strict_types=1);

namespace Switon\Rendering;

/**
 * Contract for one template engine execution.
 *
 * Use when one engine should execute a resolved template file against one `Frames` transaction.
 *
 * @see \Switon\Rendering\Engine\Php
 * @see \Switon\Rendering\Engine\Sword
 */
interface EngineInterface
{
    /**
     * Executes one resolved template file against the provided frames transaction.
     */
    public function render(string $file, Frames $frames): void;
}
