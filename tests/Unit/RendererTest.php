<?php

declare(strict_types=1);

namespace Switon\Rendering\Tests\Unit;

use Switon\Core\Exception\PreconditionException;
use Switon\Rendering\Exception\ReservedVariableException;
use Switon\Rendering\Frames;
use Switon\Rendering\Renderer;
use Switon\Rendering\Tests\Fixtures\TestableRenderer;
use Switon\Rendering\Tests\TestCase as BaseTestCase;
use Switon\Sync\MutexInterface;
use RuntimeException;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function unlink;

class RendererTest extends BaseTestCase
{
    protected Renderer $renderer;
    protected string $testViewsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = $this->container->make(Renderer::class);
        $this->testViewsDir = $this->getTestTempDir() . '/View';
        if (!is_dir($this->testViewsDir)) {
            mkdir($this->testViewsDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testViewsDir)) {
            $this->removeDirectory($this->testViewsDir);
        }
        parent::tearDown();
    }

    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    protected function createTemplateFile(string $template, string $content): string
    {
        $file = $this->testViewsDir . '/' . $template . '.phtml';
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($file, $content);
        return $file;
    }

    protected function createThemeTemplateFile(string $theme, string $template, string $content): string
    {
        $file = $this->getTestTempDir() . '/themes/' . $theme . '/View/' . $template . '.phtml';
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($file, $content);
        return $file;
    }

    public function testRenderReturnsFramesWithContent(): void
    {
        $this->createTemplateFile('test', 'Hello World');

        $frames = $this->renderer->render($this->testViewsDir . '/test');

        $this->assertSame('Hello World', $frames->content());
        $this->assertSame('Hello World', (string)$frames);
    }

    public function testRenderAcceptsFramesInput(): void
    {
        $this->createTemplateFile('test', 'Hello <?= $name ?>');

        $frames = Frames::of(['name' => 'John']);
        $result = $this->renderer->render($this->testViewsDir . '/test', [], $frames);

        $this->assertSame('Hello John', $result->content());
        $this->assertSame('Hello John', $frames->content());
    }

    public function testRenderResolvesRelativeTemplateFromCurrentTemplateStack(): void
    {
        $this->createTemplateFile('layout', 'Layout[<?php $__frames->partial("part", ["name" => $name]); ?>]');
        $this->createTemplateFile('part', 'Part: <?= $name ?>');

        $frames = $this->renderer->render($this->testViewsDir . '/layout', ['name' => 'Tom']);

        $this->assertSame('Layout[Part: Tom]', $frames->content());
    }

    public function testExistsChecksResolvedTemplatePath(): void
    {
        $this->createTemplateFile('part', 'Part');

        $this->assertTrue($this->renderer->exists($this->testViewsDir . '/part'));
        $this->assertFalse($this->renderer->exists($this->testViewsDir . '/missing'));
    }

    public function testThemeUsesThemeTemplateWhenPresentAndFallsBackWhenMissing(): void
    {
        $this->createTemplateFile('page', 'default-page');
        $this->createThemeTemplateFile('dark', 'page', 'dark-page');
        $this->createTemplateFile('fallback', 'default-fallback');

        $frames = Frames::of()->setTheme('dark');
        $themed = $this->renderer->render($this->testViewsDir . '/page', [], $frames);
        $fallback = $this->renderer->render($this->testViewsDir . '/fallback', [], Frames::of()->setTheme('dark'));

        $this->assertSame('dark-page', $themed->content());
        $this->assertSame('default-fallback', $fallback->content());
    }

    public function testThemeCacheKeyIsolatedByThemeValue(): void
    {
        $this->createTemplateFile('page', 'default-page');
        $this->createThemeTemplateFile('dark', 'page', 'dark-page');

        $dark = $this->renderer->render($this->testViewsDir . '/page', [], Frames::of()->setTheme('dark'));
        $default = $this->renderer->render($this->testViewsDir . '/page');

        $this->assertSame('dark-page', $dark->content());
        $this->assertSame('default-page', $default->content());
    }

    public function testTemplateCanCaptureSection(): void
    {
        $this->createTemplateFile(
            'section',
            '<?php $__frames->startSection("title"); ?>Title<?php $__frames->stopSection(); ?>Main[<?= $__frames->section("title") ?>]'
        );

        $frames = $this->renderer->render($this->testViewsDir . '/section');

        $this->assertSame('Main[Title]', $frames->content());
        $this->assertSame('Title', $frames->section('title'));
        $this->assertTrue($frames->hasSection('title'));
    }

    public function testTemplateCanAppendSection(): void
    {
        $this->createTemplateFile(
            'section-append',
            '<?php $__frames->startSection("title"); ?>A<?php $__frames->stopSection(); ?>'
            . '<?php $__frames->startSection("title"); ?>B<?php $__frames->appendSection(); ?>'
            . '<?= $__frames->section("title") ?>'
        );

        $frames = $this->renderer->render($this->testViewsDir . '/section-append');

        $this->assertSame('AB', $frames->content());
        $this->assertSame('AB', $frames->section('title'));
    }

    public function testTemplateCanPushStack(): void
    {
        $this->createTemplateFile(
            'stack',
            '<?php $__frames->startStack("scripts"); ?><script>a</script><?php $__frames->stopStack(); ?>'
            . '<?php $__frames->startStack("scripts"); ?><script>b</script><?php $__frames->stopStack(); ?>'
            . '<?= $__frames->renderStack("scripts") ?>'
        );

        $frames = $this->renderer->render($this->testViewsDir . '/stack');

        $this->assertSame(['<script>a</script>', '<script>b</script>'], $frames->stack('scripts'));
        $this->assertSame('<script>a</script><script>b</script>', $frames->content());
    }

    public function testTemplateCanUseOnceMarkers(): void
    {
        $this->createTemplateFile(
            'once',
            '<?php if ($__frames->once("x")): ?>A<?php endif; ?>'
            . '<?php if ($__frames->once("x")): ?>B<?php endif; ?>'
        );

        $frames = $this->renderer->render($this->testViewsDir . '/once');

        $this->assertSame('A', $frames->content());
    }

    public function testRenderRejectsReservedFramesVariableName(): void
    {
        $this->createTemplateFile('test', 'Hello World');

        $this->expectException(ReservedVariableException::class);
        $this->expectExceptionMessage('Cannot use reserved template variable name "$__frames".');

        $this->renderer->render($this->testViewsDir . '/test', ['__frames' => 'bad']);
    }

    public function testFramesCannotBeginRenderTwice(): void
    {
        $frames = Frames::of();
        $frames->beginRender();

        $this->expectException(PreconditionException::class);
        $this->expectExceptionMessage('Cannot begin render: this Frames transaction is already active.');

        $frames->beginRender();
    }

    public function testRenderFragmentThrowsExceptionWithoutActiveRenderTransaction(): void
    {
        $this->expectException(PreconditionException::class);
        $this->expectExceptionMessage('no active render transaction exists');

        $this->renderer->renderFragment(Frames::of(), $this->testViewsDir . '/part');
    }

    public function testRenderAbortsFramesWhenTemplateThrows(): void
    {
        $this->createTemplateFile('boom', '<?php throw new \\RuntimeException("boom"); ?>');
        $frames = Frames::of();

        try {
            $this->renderer->render($this->testViewsDir . '/boom', [], $frames);
            $this->fail('Expected render to throw.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
            $this->assertFalse($frames->isRendering());
        }
    }

    public function testCaptureSegmentReturnsCapturedOutputAndClearsItOnFailure(): void
    {
        $renderer = $this->container->make(\Switon\Rendering\Tests\Fixtures\TestableRenderer::class);

        $this->assertSame('hello', $renderer->captureSegmentPublic(static function (): void {
            echo 'hello';
        }));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $renderer->captureSegmentPublic(static function (): void {
            echo 'discarded';
            throw new RuntimeException('boom');
        });
    }

    public function testCaptureOwnershipChecksReportCurrentContext(): void
    {
        $renderer = $this->container->make(TestableRenderer::class);

        $this->assertFalse($renderer->isOwnedByCurrentContextPublic());
        $this->assertNull($renderer->currentOwnerIdPublic());
    }

    public function testNormalizeTemplatePathResolvesRelativePathFromCurrentTemplate(): void
    {
        $renderer = $this->container->make(TestableRenderer::class)
            ->withCurrentTemplate($this->testViewsDir . '/layout.phtml');

        $this->assertSame(
            $this->testViewsDir . '/part',
            $renderer->normalizeTemplatePathPublic('part')
        );
    }

    public function testFindTemplateFileUsesThemeThenFallsBackToDefaultTemplate(): void
    {
        $this->createTemplateFile('page', 'default-page');
        $this->createThemeTemplateFile('dark', 'page', 'dark-page');

        $renderer = $this->container->make(TestableRenderer::class);

        $themed = $renderer->findTemplateFilePublic(Frames::of()->setTheme('dark'), $this->testViewsDir . '/page');
        $default = $renderer->findTemplateFilePublic(Frames::of(), $this->testViewsDir . '/page');

        $this->assertSame([$this->getTestTempDir() . '/themes/dark/View/page.phtml', '.phtml'], $themed);
        $this->assertSame([$this->testViewsDir . '/page.phtml', '.phtml'], $default);
    }

    public function testCaptureThrowsForNestedRenderInSameContext(): void
    {
        $renderer = $this->container->make(TestableRenderer::class)
            ->withMutex($this->createStub(MutexInterface::class))
            ->withCaptureState(true, null);

        $this->expectException(PreconditionException::class);
        $this->expectExceptionMessage('Cannot start nested render capture in the same execution context');

        $renderer->capturePublic(static function (): void {
        });
    }
}
