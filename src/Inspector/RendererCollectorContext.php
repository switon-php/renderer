<?php

declare(strict_types=1);

namespace Switon\Rendering\Inspector;

use Switon\Core\ContextIsolated;

/**
 * Request-scoped context for renderer collector data.
 *
 * Stores render-event snapshots collected during one request.
 *
 * @property list<array<string, mixed>> $rendered
 *
 * @see \Switon\Rendering\Inspector\RendererCollector
 * @see \Switon\Rendering\Renderer
 */
class RendererCollectorContext implements ContextIsolated
{
    /** @var list<array<string, mixed>> */
    public array $rendered = [];
}
