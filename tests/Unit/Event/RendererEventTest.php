<?php

declare(strict_types=1);

namespace Switon\Rendering\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Switon\Rendering\Event\RendererRendered;
use Switon\Rendering\Event\RendererRendering;
use Switon\Rendering\RendererInterface;

#[AllowMockObjectsWithoutExpectations]
class RendererEventTest extends TestCase
{
    public function testRendererRenderingJsonSerializeUsesStableShape(): void
    {
        $renderer = $this->createMock(RendererInterface::class);
        $event = new RendererRendering(
            $renderer,
            '@app/View/home',
            '/workspace/project/View/home.phtml',
            ['id' => 1, 'name' => 'alice']
        );

        $result = $event->jsonSerialize();

        $this->assertSame(
            [
                'renderer' => $renderer::class,
                'template' => '@app/View/home',
                'file' => '/workspace/project/View/home.phtml',
                'vars' => [
                    '_keys' => ['id', 'name'],
                    '_count' => 2,
                ],
            ],
            $result
        );
    }

    public function testRendererRenderedJsonSerializeWorksWithEmptyVars(): void
    {
        $renderer = $this->createMock(RendererInterface::class);
        $event = new RendererRendered(
            $renderer,
            '@app/View/about',
            '/workspace/project/View/about.sword',
            []
        );

        $result = $event->jsonSerialize();

        $this->assertSame($renderer::class, $result['renderer']);
        $this->assertSame('@app/View/about', $result['template']);
        $this->assertSame('/workspace/project/View/about.sword', $result['file']);
        $this->assertSame(['_keys' => [], '_count' => 0], $result['vars']);
    }
}
