<?php

declare(strict_types=1);

namespace Switon\Rendering\Exception;

use Switon\Rendering\Exception as BaseException;

/**
 * Exception for Sword template compilation failures.
 *
 * Raised when template source cannot be parsed, read, or written.
 *
 * @see \Switon\Rendering\Exception
 * @see \Switon\Rendering\Engine\Sword\Compiler
 */
class TemplateCompilationException extends BaseException
{
}
