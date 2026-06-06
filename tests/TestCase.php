<?php

declare(strict_types=1);

namespace Switon\Rendering\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\PathAlias;
use Switon\Core\PathAliasInterface;
use Switon\Testing\Container;

/**
 * Base test case for Renderer tests.
 *
 * Provides common functionality for all Renderer tests using Container (as in real applications).
 * All dependencies are injected through Container's autowiring.
 */
abstract class TestCase extends BaseTestCase
{
    protected Container $container;
    protected EventDispatcherInterface $eventDispatcher;
    protected PathAlias $pathAlias;

    protected function setUp(): void
    {
        parent::setUp();

        // Use pre-configured test container (ContextManagerInterface and PathAliasInterface are already registered)
        $this->container = new Container();

        // Disable event dispatching (Renderer tests don't need real event handling)
        // This is equivalent to NoOp behavior - events are collected but not dispatched
        $this->container->disableEventDispatching();

        // Get EventDispatcher from container (TestEventDispatcher wrapper with dispatching disabled)
        $this->eventDispatcher = $this->container->get(EventDispatcherInterface::class);

        // Get PathAlias from container and set @root alias for tests
        $this->pathAlias = $this->container->get(PathAliasInterface::class);
        $this->pathAlias->set('@root', $this->getTestTempDir());
    }

    protected function getTestTempDir(): string
    {
        return sys_get_temp_dir() . '/switon-renderer-tests';
    }
}
