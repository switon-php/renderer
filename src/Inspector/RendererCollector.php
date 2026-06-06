<?php

declare(strict_types=1);

namespace Switon\Rendering\Inspector;

use JsonSerializable;
use Switon\Core\Attribute\Autowired;
use Switon\Core\CollectorInterface;
use Switon\Core\ContextAware;
use Switon\Core\ContextManagerInterface;
use Switon\Core\PathAliasInterface;
use Switon\Eventing\Attribute\EventListener;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Eventing\ObservabilityProbe;
use Switon\Rendering\Event\RendererRendering;

use function basename;
use function dirname;
use function getenv;
use function ltrim;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Observability collector for renderer activity.
 *
 * Use when exposing rendered-template metadata in inspector output.
 *
 * Road-signs:
 * - boot() registers event listeners
 * - onRendererRendering() records render snapshots
 * - collect() returns privacy-safe file displays
 *
 * @see \Switon\Rendering\Renderer
 * @see \Switon\Rendering\Event\RendererRendering
 * @see \Switon\Rendering\Event\RendererRendered
 * @see \Switon\Rendering\Inspector\RendererCollectorContext
 */
class RendererCollector implements CollectorInterface, ObservabilityProbe, ContextAware, JsonSerializable
{
    #[Autowired] protected ContextManagerInterface $contextManager;
    #[Autowired] protected ListenerProviderInterface $listenerProvider;
    #[Autowired] protected PathAliasInterface $pathAlias;

    /**
     * Returns the request-scoped collector context.
     */
    public function getContext(): RendererCollectorContext
    {
        return $this->contextManager->getContext($this);
    }

    /**
     * Registers renderer event listeners for this collector.
     */
    public function boot(): void
    {
        // Register event listener
        $this->listenerProvider->register($this);
    }

    /**
     * Records one renderer event snapshot into request context.
     */
    #[EventListener] public function onRendererRendering(RendererRendering $event): void
    {
        $context = $this->getContext();
        $context->rendered[] = $event->jsonSerialize();
    }

    /**
     * Collects inspector payload entries with privacy-safe display paths.
     */
    public function collect(): array
    {
        $rendered = $this->getContext()->rendered;
        $rootPath = str_replace('\\', '/', $this->pathAlias->resolve('@root'));
        $parentPath = str_replace('\\', '/', dirname($rootPath));
        $rootName = basename($rootPath);
        $homeDir = str_replace('\\', '/', getenv('HOME') ?: (getenv('USERPROFILE') ?: ''));

        $result = [];
        foreach ($rendered as $item) {
            $file = $item['file'] ?? '';
            $normalized = str_replace('\\', '/', $file);

            // Generate privacy-safe display path
            if (str_starts_with($normalized, $rootPath)) {
                $fileDisplay = $rootName . '/' . ltrim(substr($normalized, strlen($rootPath)), '/');
            } elseif (str_starts_with($normalized, $parentPath)) {
                $fileDisplay = ltrim(substr($normalized, strlen($parentPath)), '/');
            } elseif ($homeDir && str_starts_with($normalized, $homeDir)) {
                $fileDisplay = '~/' . ltrim(substr($normalized, strlen($homeDir)), '/');
            } else {
                $fileDisplay = $normalized;
            }

            $result[] = [
                'renderer' => $item['renderer'] ?? '',
                'template' => $item['template'] ?? '',
                'file' => $file,
                'file_display' => $fileDisplay,
                'vars' => $item['vars'] ?? [],
            ];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [];
    }
}
