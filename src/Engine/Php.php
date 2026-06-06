<?php

declare(strict_types=1);

namespace Switon\Rendering\Engine;

use Switon\Rendering\EngineInterface;
use Switon\Rendering\Frames;

use function extract;

/**
 * Native PHP renderer for `.phtml` templates.
 *
 * Use when templates should execute directly through PHP `require`.
 */
class Php implements EngineInterface
{
    public function render(string $file, Frames $frames): void
    {
        $__frames = $frames;
        extract($frames->all(), EXTR_SKIP);

        require $file;
    }
}
