<?php

declare(strict_types=1);

namespace Switon\Rendering\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Switon\Core\ConsoleInterface;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;
use Switon\Rendering\Command\SwordCommand;
use Switon\Rendering\Engine\Sword\Compiler;

use function str_contains;

#[AllowMockObjectsWithoutExpectations]
class SwordCommandTest extends TestCase
{
    protected SwordCommand $command;
    protected ConsoleInterface&MockObject $console;
    protected FilesystemInterface&MockObject $filesystem;
    protected PathAliasInterface&MockObject $pathAlias;
    protected Compiler&MockObject $compiler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new TestableSwordCommand();
        $this->console = $this->createMock(ConsoleInterface::class);
        $this->filesystem = $this->createMock(FilesystemInterface::class);
        $this->pathAlias = $this->createMock(PathAliasInterface::class);
        $this->compiler = $this->createMock(Compiler::class);
        $this->command->wire(
            $this->console,
            $this->filesystem,
            $this->pathAlias,
            $this->compiler,
        );
    }

    public function testCompileActionBuildsRuntimeMirrorPathsWhenReplaceIsFalse(): void
    {
        $this->filesystem->expects($this->once())
            ->method('rmdir')
            ->with('@runtime/sword');

        $this->filesystem->expects($this->exactly(4))
            ->method('glob')
            ->willReturnOnConsecutiveCalls(
                ['/var/app/View/home.sword'],
                ['/var/app/View/admin/dashboard.sword'],
                ['/var/app/Areas/Blog/View/post/list.sword'],
                ['/var/app/Areas/Blog/View/index.sword'],
            );

        $this->pathAlias->expects($this->exactly(8))
            ->method('resolve')
            ->willReturnCallback(static function (string $path): string {
                return match ($path) {
                    '@root' => '/var/app',
                    '@runtime/sword' => '/var/runtime/sword',
                    default => $path,
                };
            });

        $capturedCompiles = [];
        $this->compiler->expects($this->exactly(4))
            ->method('compileFile')
            ->willReturnCallback(function (string $source, string $compiled) use (&$capturedCompiles): Compiler {
                $capturedCompiles[] = [$source, $compiled];
                return $this->compiler;
            });

        $lines = [];
        $this->console->expects($this->exactly(5))
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

        $this->command->compileAction(false);

        $this->assertSame('delete "@runtime/sword" directory success', $lines[0]);
        $this->assertSame(
            [
                ['/var/app/View/home.sword', '/var/runtime/sword/View/home.sword'],
                ['/var/app/View/admin/dashboard.sword', '/var/runtime/sword/View/admin/dashboard.sword'],
                ['/var/app/Areas/Blog/View/post/list.sword', '/var/runtime/sword/Areas/Blog/View/post/list.sword'],
                ['/var/app/Areas/Blog/View/index.sword', '/var/runtime/sword/Areas/Blog/View/index.sword'],
            ],
            $capturedCompiles
        );
        $this->assertTrue(str_contains($lines[4], '/var/runtime/sword/Areas/Blog/View/index.sword'));
    }

    public function testCompileActionWritesPhtmlBesideSourceWhenReplaceIsTrue(): void
    {
        $this->filesystem->expects($this->once())
            ->method('rmdir')
            ->with('@runtime/sword');

        $this->filesystem->expects($this->exactly(4))
            ->method('glob')
            ->willReturnOnConsecutiveCalls(
                ['/var/app/View/home.sword'],
                [],
                [],
                [],
            );

        $this->pathAlias->expects($this->never())->method('resolve');

        $this->compiler->expects($this->once())
            ->method('compileFile')
            ->with('/var/app/View/home.sword', '/var/app/View/home.phtml')
            ->willReturn($this->compiler);

        $lines = [];
        $this->console->expects($this->exactly(2))
            ->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

        $this->command->compileAction(true);

        $this->assertSame('delete "@runtime/sword" directory success', $lines[0]);
        $this->assertTrue(str_contains($lines[1], '/var/app/View/home.phtml'));
    }
}

class TestableSwordCommand extends SwordCommand
{
    public function wire(
        ConsoleInterface    $console,
        FilesystemInterface $filesystem,
        PathAliasInterface  $pathAlias,
        Compiler            $compiler,
    ): void {
        $this->console = $console;
        $this->filesystem = $filesystem;
        $this->pathAlias = $pathAlias;
        $this->swordCompiler = $compiler;
    }
}
