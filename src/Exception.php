<?php

declare(strict_types=1);

namespace Switon\Rendering;

/**
 * Base exception for the Rendering component.
 *
 * Use when renderer-specific failures need one package-level exception root.
 *
 * @see \Switon\Rendering\Renderer
 * @see \Switon\Core\Exception
 */
class Exception extends \Switon\Core\Exception
{
}
