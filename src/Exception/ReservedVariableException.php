<?php

declare(strict_types=1);

namespace Switon\Rendering\Exception;

use Switon\Rendering\Exception as BaseException;

/**
 * Exception for template variables that collide with renderer runtime bindings.
 *
 * Raised when render variables include the reserved `$__frames` runtime name.
 *
 * @see \Switon\Rendering\Exception
 * @see \Switon\Rendering\Renderer
 */
class ReservedVariableException extends BaseException
{
}
