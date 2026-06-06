<?php

declare(strict_types=1);

namespace Switon\Rendering\Tests\Fixtures;

use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\PathAliasInterface;
use Switon\Rendering\EngineInterface;
use Switon\Rendering\Frames;
use Switon\Rendering\Renderer;
use Switon\Sync\MutexInterface;

class TestableRenderer extends Renderer
{
    public function withEventDispatcher(EventDispatcherInterface $eventDispatcher): static
    {
        $this->eventDispatcher = $eventDispatcher;
        return $this;
    }

    public function withPathAlias(PathAliasInterface $pathAlias): static
    {
        $this->pathAlias = $pathAlias;
        return $this;
    }

    public function withMutex(MutexInterface $mutex): static
    {
        $this->mutex = $mutex;
        return $this;
    }

    public function withCaptureState(bool $active, ?int $ownerId): static
    {
        $this->captureActive = $active;
        $this->captureOwnerId = $ownerId;
        return $this;
    }

    /** @param array<string, EngineInterface> $engines */
    public function withEngines(array $engines): static
    {
        $this->engines = $engines;
        return $this;
    }

    public function withCurrentTemplate(string $template): static
    {
        $this->templates = [$template];
        return $this;
    }

    public function normalizeTemplatePathPublic(string $template): string
    {
        return $this->normalizeTemplatePath($template);
    }

    /** @return array{0: string, 1: string} */
    public function findTemplateFilePublic(Frames $frames, string $template): array
    {
        return $this->findTemplateFile($frames, $template);
    }

    public function captureSegmentPublic(callable $callback): string
    {
        return $this->captureSegment($callback);
    }

    public function capturePublic(callable $callback): string
    {
        return $this->capture($callback);
    }

    public function isOwnedByCurrentContextPublic(): bool
    {
        return $this->isOwnedByCurrentContext();
    }

    public function currentOwnerIdPublic(): ?int
    {
        return $this->currentOwnerId();
    }
}
