<?php

declare(strict_types=1);

namespace Switon\Rendering\Tests\Unit\Inspector;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Switon\Core\ContextManagerInterface;
use Switon\Core\PathAliasInterface;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Rendering\Event\RendererRendering;
use Switon\Rendering\Inspector\RendererCollector;
use Switon\Rendering\Inspector\RendererCollectorContext;
use Switon\Rendering\RendererInterface;

#[AllowMockObjectsWithoutExpectations]
class RendererCollectorTest extends TestCase
{
    protected ?string $originalHome = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalHome = getenv('HOME') ?: null;
    }

    protected function tearDown(): void
    {
        if ($this->originalHome === null) {
            putenv('HOME');
        } else {
            putenv('HOME=' . $this->originalHome);
        }
        parent::tearDown();
    }

    public function testBootRegistersCollectorIntoListenerProvider(): void
    {
        $collector = new TestableRendererCollector();
        $context = new RendererCollectorContext();

        $contextManager = $this->createMock(ContextManagerInterface::class);
        $contextManager->method('getContext')->willReturn($context);

        $listenerProvider = $this->createMock(ListenerProviderInterface::class);
        $listenerProvider->expects($this->once())
            ->method('register')
            ->with($collector);

        $pathAlias = $this->createMock(PathAliasInterface::class);
        $pathAlias->method('resolve')->willReturn('/workspace/project');

        $collector->wire($contextManager, $listenerProvider, $pathAlias);

        $collector->boot();
    }

    public function testOnRendererRenderingAppendsSerializedEventIntoContext(): void
    {
        $collector = new TestableRendererCollector();
        $context = new RendererCollectorContext();

        $contextManager = $this->createMock(ContextManagerInterface::class);
        $contextManager->method('getContext')->willReturn($context);

        $collector->wire(
            $contextManager,
            $this->createMock(ListenerProviderInterface::class),
            $this->createMock(PathAliasInterface::class),
        );

        $renderer = $this->createMock(RendererInterface::class);
        $event = new RendererRendering($renderer, '@app/View/home', '/workspace/project/View/home.phtml', ['user' => 'a', 'id' => 1]);

        $collector->onRendererRendering($event);

        $this->assertCount(1, $context->rendered);
        $item = $context->rendered[0];
        $this->assertSame('@app/View/home', $item['template']);
        $this->assertSame('/workspace/project/View/home.phtml', $item['file']);
        $this->assertSame(['_keys' => ['user', 'id'], '_count' => 2], $item['vars']);
    }

    public function testCollectBuildsPrivacySafeDisplayPaths(): void
    {
        putenv('HOME=/Users/tester');

        $collector = new TestableRendererCollector();
        $context = new RendererCollectorContext();
        $context->rendered = [
            [
                'renderer' => 'RendererA',
                'template' => '@app/View/home',
                'file' => '/workspace/project/View/home.phtml',
                'vars' => ['_keys' => ['a'], '_count' => 1],
            ],
            [
                'renderer' => 'RendererB',
                'template' => '@app/View/about',
                'file' => '/workspace/other/View/about.phtml',
                'vars' => ['_keys' => [], '_count' => 0],
            ],
            [
                'renderer' => 'RendererC',
                'template' => '@app/View/user',
                'file' => '/Users/tester/private/View/user.phtml',
                'vars' => ['_keys' => ['user'], '_count' => 1],
            ],
            [
                'renderer' => 'RendererD',
                'template' => '@app/View/raw',
                'file' => '/opt/app/View/raw.phtml',
                'vars' => ['_keys' => [], '_count' => 0],
            ],
        ];

        $contextManager = $this->createMock(ContextManagerInterface::class);
        $contextManager->method('getContext')->willReturn($context);

        $pathAlias = $this->createMock(PathAliasInterface::class);
        $pathAlias->method('resolve')
            ->with('@root')
            ->willReturn('/workspace/project');

        $collector->wire(
            $contextManager,
            $this->createMock(ListenerProviderInterface::class),
            $pathAlias,
        );

        $result = $collector->collect();

        $this->assertSame('project/View/home.phtml', $result[0]['file_display']);
        $this->assertSame('other/View/about.phtml', $result[1]['file_display']);
        $this->assertSame('~/private/View/user.phtml', $result[2]['file_display']);
        $this->assertSame('/opt/app/View/raw.phtml', $result[3]['file_display']);
        $this->assertSame('RendererA', $result[0]['renderer']);
        $this->assertSame('@app/View/home', $result[0]['template']);
        $this->assertSame(['_keys' => ['a'], '_count' => 1], $result[0]['vars']);
    }

    public function testJsonSerializeReturnsEmptyArray(): void
    {
        $collector = new RendererCollector();
        $this->assertSame([], $collector->jsonSerialize());
    }
}

class TestableRendererCollector extends RendererCollector
{
    public function wire(
        ContextManagerInterface   $contextManager,
        ListenerProviderInterface $listenerProvider,
        PathAliasInterface        $pathAlias,
    ): void {
        $this->contextManager = $contextManager;
        $this->listenerProvider = $listenerProvider;
        $this->pathAlias = $pathAlias;
    }
}
