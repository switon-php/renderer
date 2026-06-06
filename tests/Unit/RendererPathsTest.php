<?php

declare(strict_types=1);

namespace Switon\Rendering\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Core\PathAliasInterface;
use Switon\Rendering\EngineInterface;
use Switon\Rendering\Frames;
use Switon\Rendering\Tests\Fixtures\TestableRenderer;

use function array_diff;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

class RendererPathsTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/switon-renderer-tests/' . uniqid('renderer-', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    public function testNormalizeTemplatePathUsesCurrentTemplateWhenRelative(): void
    {
        $renderer = $this->renderer()->withCurrentTemplate($this->tempDir . '/View/layout');

        $this->assertSame(
            $this->tempDir . '/View/part',
            $renderer->normalizeTemplatePathPublic('part')
        );
        $this->assertSame('/absolute/path', $renderer->normalizeTemplatePathPublic('/absolute/path'));
    }

    public function testFindTemplateFileUsesThemeAndCachesResult(): void
    {
        $renderer = $this->renderer();
        $templateDir = $this->tempDir . '/View';
        $themeDir = $this->tempDir . '/themes/dark/View';
        mkdir($templateDir, 0755, true);
        mkdir($themeDir, 0755, true);
        file_put_contents($themeDir . '/page.phtml', 'dark');

        $frames = Frames::of()->setTheme('dark');
        $template = $this->tempDir . '/View/page';

        $this->assertSame([$themeDir . '/page.phtml', '.phtml'], $renderer->findTemplateFilePublic($frames, $template));
        $this->assertSame([$themeDir . '/page.phtml', '.phtml'], $renderer->findTemplateFilePublic($frames, $template));
    }

    public function testFindTemplateFileThrowsForMissingTemplate(): void
    {
        $renderer = $this->renderer();

        $this->expectException(\Switon\Core\Exception\FileNotFoundException::class);
        $renderer->findTemplateFilePublic(Frames::of(), $this->tempDir . '/View/missing');
    }

    protected function renderer(): TestableRenderer
    {
        $pathAlias = $this->createStub(PathAliasInterface::class);
        $tempDir = $this->tempDir;
        $pathAlias->method('resolve')->willReturnCallback(static fn (string $path): string => match ($path) {
            '@root' => $tempDir,
            default => $path,
        });

        $engine = $this->createStub(EngineInterface::class);
        $engine->method('render')->willReturnCallback(static function (): void {
        });

        return (new TestableRenderer())
            ->withPathAlias($pathAlias)
            ->withEngines(['.phtml' => $engine]);
    }

    protected function removeDirectory(string $dir): void
    {
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
