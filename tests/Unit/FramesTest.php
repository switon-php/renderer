<?php

declare(strict_types=1);

namespace Switon\Rendering\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Core\Exception\PreconditionException;
use Switon\Core\Exception\RuntimeException;
use Switon\Rendering\Frames;
use Switon\Rendering\Renderer;

class FramesTest extends TestCase
{
    public function testAbortRenderResetsRenderingState(): void
    {
        $frames = Frames::of();
        $frames->beginRender();
        $frames->abortRender();

        $this->assertFalse($frames->isRendering());
    }

    public function testSetContentUpdatesContentAndStringCast(): void
    {
        $frames = Frames::of()->setContent('Hello');

        $this->assertSame('Hello', $frames->content());
        $this->assertSame('Hello', (string)$frames);
    }

    public function testPartialEchoesRendererFragment(): void
    {
        $frames = Frames::of();
        $renderer = $this->getMockBuilder(Renderer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['renderFragment'])
            ->getMock();
        $renderer->expects($this->once())
            ->method('renderFragment')
            ->with($this->identicalTo($frames), 'part', ['name' => 'Tom'])
            ->willReturn('fragment');

        $frames->attachRenderer($renderer);

        ob_start();
        $frames->partial('part', ['name' => 'Tom']);
        $output = ob_get_clean();

        $this->assertSame('fragment', $output);
    }

    public function testPartialThrowsWithoutAttachedRenderer(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Frames renderer runtime is not attached.');

        Frames::of()->partial('part');
    }

    public function testStartSectionUsesDefaultWithoutRenderRuntime(): void
    {
        $frames = Frames::of();
        $frames->startSection('title', 'Default title');

        $this->assertSame('Default title', $frames->section('title'));
        $this->assertTrue($frames->hasSection('title'));
    }

    public function testStartStackThrowsWithoutRenderRuntime(): void
    {
        $this->expectException(PreconditionException::class);
        $this->expectExceptionMessage('Cannot start stack: no active render transaction exists.');

        Frames::of()->startStack('scripts');
    }

    public function testStopSectionThrowsWithoutCapture(): void
    {
        $frames = Frames::of();
        $frames->beginRender();

        $this->expectException(PreconditionException::class);
        $this->expectExceptionMessage('Cannot stop section: no active section frame exists.');

        $frames->stopSection();
    }

    public function testAppendSectionAppendsCapturedOutputToSection(): void
    {
        $frames = Frames::of();
        $frames->beginRender();
        $frames->startSection('body');
        echo 'chunk';
        $frames->appendSection();

        $this->assertSame('chunk', $frames->section('body'));
    }

    public function testStopSectionAppendsWhenSectionAlreadyExistsAndOverwriteIsFalse(): void
    {
        $frames = Frames::of();
        $frames->beginRender();
        $frames->startSection('title');
        echo 'a';
        $frames->stopSection(false);
        $frames->startSection('title');
        echo 'b';
        $frames->stopSection(false);

        $this->assertSame('ab', $frames->section('title'));
    }

    public function testAppendSectionThrowsWhenActiveCaptureIsStack(): void
    {
        $frames = Frames::of();
        $frames->beginRender();
        $frames->startStack('scripts');

        try {
            $frames->appendSection();
            $this->fail('Expected PreconditionException');
        } catch (PreconditionException $e) {
            $this->assertStringContainsString('append section', $e->getMessage());
            $this->assertStringContainsString('section', $e->getMessage());
        } finally {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            $frames->abortRender();
        }
    }
}
