<?php

declare(strict_types=1);

namespace Switon\Rendering\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use Switon\Core\AppInterface;
use Switon\Core\PathAliasInterface;
use ReflectionProperty;
use Switon\Rendering\Engine\Sword;
use Switon\Rendering\Engine\Sword\Compiler;
use Switon\Rendering\Frames;
use Switon\Rendering\Tests\Fixtures\TestableSword;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function str_contains;
use function str_starts_with;
use function sys_get_temp_dir;
use function unlink;

class SwordTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/switon-renderer-tests/' . uniqid('sword-', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    public function testConstructorUsesEmptyDocRootWhenDocumentRootIsMissingFromServer(): void
    {
        $saved = $_SERVER['DOCUMENT_ROOT'] ?? null;
        unset($_SERVER['DOCUMENT_ROOT']);

        try {
            $property = new ReflectionProperty(Sword::class, 'doc_root');
            $sword = new TestableSword();
            $this->assertSame('', $property->getValue($sword));
        } finally {
            if ($saved !== null) {
                $_SERVER['DOCUMENT_ROOT'] = $saved;
            } else {
                unset($_SERVER['DOCUMENT_ROOT']);
            }
        }
    }

    public function testGetCompiledFileCompilesWhenDebugIsEnabled(): void
    {
        $source = $this->tempDir . '/View/home.sword';
        $compiled = $this->tempDir . '/runtime/sword/View/home.sword';
        mkdir(dirname($source), 0755, true);
        mkdir(dirname($compiled), 0755, true);
        file_put_contents($source, 'hello');
        file_put_contents($compiled, 'old');

        $app = $this->createStub(AppInterface::class);
        $app->method('isDebug')->willReturn(true);

        $pathAlias = $this->createStub(PathAliasInterface::class);
        $tempDir = $this->tempDir;
        $pathAlias->method('resolve')->willReturnCallback(static fn (string $path): string => match ($path) {
            '@root' => $tempDir,
            '@runtime/sword/View/home.sword' => $compiled,
            default => $path,
        });

        $compiler = $this->createMock(Compiler::class);
        $compiler->expects($this->once())->method('compileFile')->with($source, $compiled);

        $sword = (new TestableSword($this->tempDir))
            ->withApp($app)
            ->withPathAlias($pathAlias)
            ->withCompiler($compiler);

        $this->assertSame($compiled, $sword->getCompiledFile($source));
    }

    public function testGetCompiledFileSkipsCompileWhenCacheIsFresh(): void
    {
        $source = $this->tempDir . '/View/home.sword';
        $compiled = $this->tempDir . '/runtime/sword/View/home.sword';
        mkdir(dirname($source), 0755, true);
        mkdir(dirname($compiled), 0755, true);
        file_put_contents($source, 'hello');
        file_put_contents($compiled, 'compiled');
        touch($compiled, time() + 30);

        $app = $this->createStub(AppInterface::class);
        $app->method('isDebug')->willReturn(false);

        $pathAlias = $this->createStub(PathAliasInterface::class);
        $tempDir = $this->tempDir;
        $pathAlias->method('resolve')->willReturnCallback(static fn (string $path): string => match ($path) {
            '@root' => $tempDir,
            '@runtime/sword/View/home.sword' => $compiled,
            default => $path,
        });

        $compiler = $this->createMock(Compiler::class);
        $compiler->expects($this->never())->method('compileFile');

        $sword = (new TestableSword($this->tempDir))
            ->withApp($app)
            ->withPathAlias($pathAlias)
            ->withCompiler($compiler);

        $this->assertSame($compiled, $sword->getCompiledFile($source));
    }

    public function testGetCompiledFileUsesDocRootWhenSourceIsOutsideResolvedRoot(): void
    {
        $base = $this->tempDir;
        $webRoot = $base . '/web';
        $source = $base . '/public/view/docroot.sword';
        mkdir(dirname($source), 0755, true);
        file_put_contents($source, 'hello');
        $compiled = $base . '/runtime/sword/public/view/docroot.sword';
        mkdir(dirname($compiled), 0755, true);
        file_put_contents($compiled, 'old');

        $app = $this->createStub(AppInterface::class);
        $app->method('isDebug')->willReturn(true);

        $pathAlias = $this->createStub(PathAliasInterface::class);
        $pathAlias->method('resolve')->willReturnCallback(static function (string $path) use ($compiled, $webRoot): string {
            if ($path === '@root') {
                return $webRoot;
            }
            if (str_contains($path, 'docroot.sword')) {
                return $compiled;
            }

            return $path;
        });

        $compiler = $this->createMock(Compiler::class);
        $compiler->expects($this->once())->method('compileFile')->with($source, $compiled);

        $sword = (new TestableSword($base))
            ->withApp($app)
            ->withPathAlias($pathAlias)
            ->withCompiler($compiler);

        $this->assertSame($compiled, $sword->getCompiledFile($source));
    }

    public function testGetCompiledFileUsesRuntimeMirrorWhenDocRootEmptyAndSourceOutsideRoot(): void
    {
        $source = $this->tempDir . '/external/pkg/view.sword';
        mkdir(dirname($source), 0755, true);
        file_put_contents($source, 'blade');
        $compiled = $this->tempDir . '/runtime-mirror/external/pkg/view.sword';
        mkdir(dirname($compiled), 0755, true);
        file_put_contents($compiled, 'stale');

        $app = $this->createStub(AppInterface::class);
        $app->method('isDebug')->willReturn(true);

        $otherRoot = $this->tempDir . '/other-app-root';
        mkdir($otherRoot, 0755, true);

        $pathAlias = $this->createStub(PathAliasInterface::class);
        $pathAlias->method('resolve')->willReturnCallback(static function (string $path) use ($otherRoot, $compiled): string {
            if ($path === '@root') {
                return $otherRoot;
            }
            if (str_starts_with($path, '@runtime/sword')) {
                return $compiled;
            }

            return $path;
        });

        $compiler = $this->createMock(Compiler::class);
        $compiler->expects($this->once())->method('compileFile')->with($source, $compiled);

        $sword = (new TestableSword(''))
            ->withApp($app)
            ->withPathAlias($pathAlias)
            ->withCompiler($compiler);

        $this->assertSame($compiled, $sword->getCompiledFile($source));
    }

    public function testRenderCachesCompiledPathPerTemplate(): void
    {
        $source = $this->tempDir . '/View/home.sword';
        $compiled = $this->tempDir . '/runtime/sword/View/home.sword';
        mkdir(dirname($source), 0755, true);
        mkdir(dirname($compiled), 0755, true);
        file_put_contents($source, '<?php echo "ok";');
        file_put_contents($compiled, '<?php echo "ok";');

        $app = $this->createStub(AppInterface::class);
        $app->method('isDebug')->willReturn(false);

        $pathAlias = $this->createStub(PathAliasInterface::class);
        $tempDir = $this->tempDir;
        $pathAlias->method('resolve')->willReturnCallback(static fn (string $path): string => match ($path) {
            '@root' => $tempDir,
            '@runtime/sword/View/home.sword' => $compiled,
            default => $path,
        });

        $compiler = $this->createMock(Compiler::class);
        $compiler->expects($this->never())->method('compileFile');

        $sword = (new TestableSword($this->tempDir))
            ->withApp($app)
            ->withPathAlias($pathAlias)
            ->withCompiler($compiler);

        $frames = Frames::of();
        ob_start();
        $sword->render($source, $frames);
        $sword->render($source, $frames);
        ob_end_clean();
        $this->assertSame(1, $sword->compiledFileLookups);
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
