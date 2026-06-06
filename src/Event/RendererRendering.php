<?php

declare(strict_types=1);

namespace Switon\Rendering\Event;

use JsonSerializable;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Rendering\RendererInterface;

/**
 * Event emitted before renderer executes a template.
 *
 * Log category: renderer lifecycle.
 *
 * @see \Switon\Rendering\Renderer
 * @see \Switon\Rendering\Event\RendererRendered
 */
#[EventLevel(Severity::DEBUG)]
class RendererRendering implements JsonSerializable
{
    /**
     * @param RendererInterface $renderer Renderer instance
     * @param string $template Requested template path
     * @param string $file Resolved template file path
     * @param array<string, mixed> $vars Template variables
     */
    public function __construct(
        public RendererInterface $renderer,
        public string            $template,
        public string            $file,
        public array             $vars,
    ) {

    }

    /**
     * Returns a privacy-safe event payload with variable keys and count only.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'renderer' => $this->renderer::class,
            'template' => $this->template,
            'file' => $this->file,
            'vars' => [
                '_keys' => array_keys($this->vars),
                '_count' => count($this->vars),
            ],
        ];
    }
}
