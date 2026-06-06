<?php

declare(strict_types=1);

namespace Switon\Rendering\Tests\Unit\Engine\Sword;

use PHPUnit\Framework\TestCase;
use Switon\Core\Exception\FileNotFoundException;
use Switon\Core\Exception\RuntimeException;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAlias;
use Switon\Core\PathAliasInterface;
use Switon\Rendering\Exception\TemplateCompilationException;
use Switon\Rendering\Tests\Fixtures\TestableCompiler;

use function array_diff;
use function file_put_contents;
use function is_dir;
use function md5_file;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;

class CompilerTest extends TestCase
{
    protected TestableCompiler $compiler;
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/switon-renderer-tests/' . uniqid('compiler-', true);
        mkdir($this->tempDir, 0755, true);
        $this->compiler = new TestableCompiler();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    public function testCompileStringPreservesVerbatimBlocksAndHashesAssets(): void
    {
        $publicDir = $this->tempDir . '/public';
        $assetDir = $publicDir . '/img';
        mkdir($assetDir, 0755, true);
        file_put_contents($assetDir . '/logo.png', 'asset');

        $pathAlias = new PathAlias();
        $pathAlias->set('@public', $publicDir);

        $this->compiler
            ->withPathAlias($pathAlias)
            ->withHashLength(8)
            ->directive('caps', static fn (?string $expression): string => 'DIRECTIVE_OK');

        $result = $this->compiler->compileString(
            <<<'TPL'
@verbatim {{ $raw }} @endverbatim
{{-- comment --}}
<img src="/img/logo.png">
Hello {{ $name or 'Guest' }}
{!! $html !!}
{{ url('/docs') }}
@caps('ok')
TPL
        );

        $this->assertStringContainsString('{{ $raw }}', $result);
        $this->assertStringContainsString('<?php /* comment */ ?>', $result);
        $this->assertStringContainsString('src="/img/logo.png?v=' . substr(md5_file($assetDir . '/logo.png'), 0, 8) . '"', $result);
        $this->assertStringContainsString("<?= e(isset(\$name) ? \$name : 'Guest'); ?>", $result);
        $this->assertStringContainsString('<?= $html; ?>', $result);
        $this->assertStringContainsString("<?= url('/docs') ?>", $result);
        $this->assertStringContainsString('DIRECTIVE_OK', $result);
    }

    public function testCompileStringKeepsExistingHtmlAndSkipsMissingAssets(): void
    {
        $pathAlias = new PathAlias();
        $pathAlias->set('@public', $this->tempDir . '/public');
        $this->compiler
            ->withPathAlias($pathAlias)
            ->withHashLength(8);

        $result = $this->compiler->compileString('<img src="/docs/page.html"><img src="/img/missing.png">');

        $this->assertSame('<img src="/docs/page.html"><img src="/img/missing.png">', $result);
    }

    public function testCompilerConstructorMergesSafeFunctions(): void
    {
        $compiler = new TestableCompiler('custom1, custom2');

        $this->assertSame(
            '<?= custom1($value) ?> <?= custom2($value) ?>',
            $compiler->compileEscapedEchosPublic('{{ custom1($value) }} {{ custom2($value) }}')
        );
    }

    public function testCompileDirectivesCoverControlFlowBranches(): void
    {
        $foreachStack = [];
        $switchStack = [];

        $this->assertSame('<?php if($ready): ?>', $this->compiler->compileIfPublic('($ready)'));
        $this->assertSame('<?php elseif($ready): ?>', $this->compiler->compileElseIfPublic('($ready)'));
        $this->assertSame('<?php else: ?>', $this->compiler->compileElsePublic());
        $this->assertSame('<?php for($i = 0; $i < 3; $i++): ?>', $this->compiler->compileForPublic('($i = 0; $i < 3; $i++)'));
        $this->assertSame('<?php while($running): ?>', $this->compiler->compileWhilePublic('($running)'));
        $this->assertSame('<?php endwhile; ?>', $this->compiler->compileEndWhilePublic());
        $this->assertSame('<?php endfor; ?>', $this->compiler->compileEndForPublic());
        $this->assertSame('<?php if ($__frames->once(__FILE__ . \':\' . __LINE__)): ?>', $this->compiler->compileOncePublic());
        $this->assertSame('<?php endif; ?>', $this->compiler->compileEndOncePublic());
        $this->assertSame('<?php break; ?>', $this->compiler->compileBreakPublic(null));
        $this->assertSame('<?php if(2) break; ?>', $this->compiler->compileBreakPublic('(2)'));
        $this->assertSame('<?php continue; ?>', $this->compiler->compileContinuePublic(null));
        $this->assertSame('<?php if(2) continue; ?>', $this->compiler->compileContinuePublic('(2)'));
        $this->assertSame('<?php $x = 1; ?>', $this->compiler->compilePhpPublic('($x = 1)'));
        $this->assertSame('<?php ', $this->compiler->compilePhpPublic(''));
        $this->assertSame(' ?>', $this->compiler->compileEndPhpPublic());

        $this->assertSame('<?php switch($type):', $this->compiler->compileSwitchPublic('($type)', $switchStack));
        $this->assertSame([true], $switchStack);
        $this->assertSame('case (1): ?>', $this->compiler->compileCasePublic('(1)', $switchStack));
        $this->assertSame([false], $switchStack);
        $this->assertSame('<?php case (2): ?>', $this->compiler->compileCasePublic('(2)', $switchStack));
        $this->assertSame('<?php default: ?>', $this->compiler->compileDefaultPublic(null, $switchStack));
        $this->assertSame('<?php endswitch; ?>', $this->compiler->compileEndSwitchPublic(null, $switchStack));
        $this->assertSame([], $switchStack);

        $this->assertSame(
            '<?php $__loopData1 = $items; $loop = new \\Switon\\Rendering\\Engine\\Sword\\Loop($__loopData1, $loop ?? null); $index = -1; foreach($__loopData1 as $item): $index++; $loop->step(); ?>',
            $this->compiler->compileForeachPublic('($items as $item)', $foreachStack)
        );
        $this->assertSame([false], $foreachStack);
        $this->assertSame('<?php endforeach; $loop = $loop->parent; ?> <?php if($index === -1): ?>', $this->compiler->compileForeachElsePublic(null, $foreachStack));
        $this->assertSame([true], $foreachStack);
        $this->assertSame('<?php endif; ?>', $this->compiler->compileEndForeachPublic(null, $foreachStack));
        $this->assertSame([], $foreachStack);

        $noElseStack = [false];
        $this->assertSame('<?php endforeach; $loop = $loop->parent; ?>', $this->compiler->compileEndForeachPublic(null, $noElseStack));
        $this->assertSame([], $noElseStack);
    }

    public function testCompileDirectivesCoverRuntimeHelpers(): void
    {
        $this->assertSame(
            "<?php if (\\Switon\\Core\\App::get('Switon\\Authorizing\\AuthorizationInterface')->can('edit')): ?>'body'<?php endif ?>",
            $this->compiler->compileAllowPublic("('edit','body')")
        );
        $this->assertSame(
            "<?php if (\\Switon\\Core\\App::get('Switon\\Authorizing\\AuthorizationInterface')->can(\$user)): ?>",
            $this->compiler->compileCanPublic('($user)')
        );
        $this->assertSame(
            "<?php if (!\\Switon\\Core\\App::get('Switon\\Authorizing\\AuthorizationInterface')->can(\$user)): ?>",
            $this->compiler->compileCannotPublic('($user)')
        );
        $this->assertSame('<?php if (!($guest)): ?>', $this->compiler->compileUnlessPublic('($guest)'));
        $this->assertSame('<?php endif; ?>', $this->compiler->compileEndUnlessPublic());
        $this->assertSame('<?php if(isset($value)): ?>', $this->compiler->compileIssetPublic('($value)'));
        $this->assertSame('<?php endif; ?>', $this->compiler->compileEndIssetPublic());
        $this->assertSame('<?php if(empty($items)): ?>', $this->compiler->compileEmptyPublic('($items)'));
        $this->assertSame('<?php endif; ?>', $this->compiler->compileEndEmptyPublic());
        $this->assertSame('<?php $__frames->partial(\'sidebar\', [\'x\' => 1]) ?>', $this->compiler->compilePartialPublic("('sidebar', ['x' => 1])"));
        $this->assertSame('<?php \\Switon\\Core\\App::get(\'Switon\\Viewing\\ViewInterface\')->block(\'main\') ?>', $this->compiler->compileBlockPublic("('main')"));
        $this->assertSame('<?= $__frames->section(\'title\'); ?>', $this->compiler->compileYieldPublic("('title')"));
        $this->assertSame('<?php $__frames->startSection(\'title\'); ?>', $this->compiler->compileSectionPublic("('title')"));
        $this->assertSame('<?php $__frames->appendSection(); ?>', $this->compiler->compileAppendPublic());
        $this->assertSame('<?php $__frames->stopSection(); ?>', $this->compiler->compileEndSectionPublic());
        $this->assertSame("<?php \\Switon\\Core\\App::get('Switon\\Viewing\\ViewInterface')->setLayout('layouts/main'); ?>", $this->compiler->compileLayoutPublic("('layouts/main')"));
        $this->assertSame("<?php \\Switon\\Core\\App::get('Switon\\Viewing\\ViewInterface')->disableLayout(); ?>", $this->compiler->compileLayoutPublic('(false)'));
        $this->assertSame('<?= \\Switon\\Core\\App::get(\'Switon\\Viewing\\ViewInterface\')->getContent(); ?>', $this->compiler->compileContentPublic());
        $this->assertSame('<?php \\Switon\\Core\\App::get(\'Switon\\Viewing\\FlashInterface\')->output() ?>', $this->compiler->compileFlashPublic());
        $this->assertSame("<?php \$service = \\Switon\\Core\\App::get('Switon\\Foo\\Bar'); ?>", $this->compiler->compileInjectPublic("('service', 'Switon\\Foo\\Bar')"));
        $this->assertSame("<?php \$Baz = \\Switon\\Core\\App::get('Switon\\Foo\\Baz'); ?>", $this->compiler->compileInjectPublic("('Switon\\Foo\\Baz')"));
        $this->assertSame('<?php $__frames->startStack(\'scripts\'); ?>', $this->compiler->compilePushPublic("('scripts')"));
        $this->assertSame('<?= $__frames->renderStack(\'scripts\'); ?>', $this->compiler->compileStackPublic("('scripts')"));
        $this->assertSame('<?php $__frames->stopStack(); ?>', $this->compiler->compileEndPushPublic());
        $this->assertSame('<?php \\Switon\\Core\\App::get(\'Switon\\Viewing\\ViewInterface\')->widget(\'hero\'); ?>', $this->compiler->compileWidgetPublic("('hero')"));
        $this->assertSame('<?php \\Switon\\Core\\App::get(\'Switon\\Viewing\\ViewInterface\')->setMaxAge(60); ?>', $this->compiler->compileMaxAgePublic('(60)'));
        $switchStack = [];
        $this->assertSame('<?php default: ?>', $this->compiler->compileDefaultPublic(null, $switchStack));

        $switchStackFirstDefault = [true];
        $this->assertSame('default: ?>', $this->compiler->compileDefaultPublic(null, $switchStackFirstDefault));
        $this->assertSame([false], $switchStackFirstDefault);
    }

    public function testCompileEchosPreferRawTagsAndSafeExpressions(): void
    {
        $this->compiler
            ->withRawTags(['{!!', '!!}'])
            ->withEscapedTags(['{{', '}}']);

        $this->assertSame(
            '<?= e($name); ?> raw <?= $name; ?> safe <?= url(\'/docs\') ?> escaped <?= e(isset($title) ? $title : \'Fallback\'); ?>',
            $this->compiler->compileEchosPublic('{{ $name }} raw {!! $name !!} safe {{ url(\'/docs\') }} escaped {{ $title or \'Fallback\' }}')
        );
    }

    public function testGetEchoMethodsSortsLongestTagsFirst(): void
    {
        $this->compiler
            ->withRawTags(['{{{', '}}}'])
            ->withEscapedTags(['{{', '}}']);

        $this->assertSame(
            ['compileRawEchos' => 3, 'compileEscapedEchos' => 2],
            $this->compiler->getEchoMethodsPublic()
        );
    }

    public function testCompileRawAndEscapedEchosHandleEscapesAndTrailingWhitespace(): void
    {
        $this->assertSame(
            '{!! $name !!} next',
            $this->compiler->compileRawEchosPublic('@{!! $name !!} next')
        );
        $this->assertSame(
            '<?= $name; ?>' . "\n",
            $this->compiler->compileRawEchosPublic('{!! $name !!}' . "\n")
        );
        $this->assertSame(
            '{{ $name }} next',
            $this->compiler->compileEscapedEchosPublic('@{{ $name }} next')
        );
        $this->assertSame(
            '<?= e($name); ?> safe <?= url(\'/docs\') ?> plain <?= e($title); ?>',
            $this->compiler->compileEscapedEchosPublic('{{ $name }} safe {{ url(\'/docs\') }} plain {{ $title }}')
        );
    }

    public function testCompileControlFlowClosersReturnExpectedFragments(): void
    {
        $this->assertSame('<?php endif; ?>', $this->compiler->compileEndCanPublic());
        $this->assertSame('<?php endif; ?>', $this->compiler->compileEndCannotPublic());
        $this->assertSame('<?php endif; ?>', $this->compiler->compileEndIfPublic());
    }

    public function testCompileStringThrowsWhenForeachNeverClosed(): void
    {
        $this->expectException(TemplateCompilationException::class);
        $this->expectExceptionMessage('Unclosed @foreach');

        $this->compiler->compileString('@foreach($items as $item)');
    }

    public function testCompileStringThrowsWhenSwitchNeverClosed(): void
    {
        $this->expectException(TemplateCompilationException::class);
        $this->expectExceptionMessage('Unclosed @switch');

        $this->compiler->compileString('@switch($type)');
    }

    public function testGetEchoMethodsPrefersRawWhenTagLengthsAreEqual(): void
    {
        $this->compiler
            ->withRawTags(['{{', '}}'])
            ->withEscapedTags(['[[', ']]']);

        $methods = $this->compiler->getEchoMethodsPublic();

        $this->assertSame(['compileRawEchos' => 2, 'compileEscapedEchos' => 2], $methods);
        $this->assertSame('compileRawEchos', array_key_first($methods));
    }

    public function testGetEchoMethodsOrdersEscapedBeforeRawWhenEscapedOpenTagIsLonger(): void
    {
        $this->compiler
            ->withRawTags(['{{', '}}'])
            ->withEscapedTags(['{{{', '}}}']);

        $methods = $this->compiler->getEchoMethodsPublic();

        $this->assertSame(['compileEscapedEchos' => 3, 'compileRawEchos' => 2], $methods);
        $this->assertSame('compileEscapedEchos', array_key_first($methods));
    }

    public function testCompileStringCompilesBreakDirectiveWithoutParentheses(): void
    {
        $out = $this->compiler->compileString("a @break \nb");

        $this->assertStringContainsString('<?php break; ?>', $out);
        $this->assertStringContainsString('a ', $out);
    }

    public function testCompileStringWithLeadingPhpBlockThenMarkup(): void
    {
        $out = $this->compiler->compileString("<?php echo 1;\n?><div>@if(\$ready)</div>");

        $this->assertStringContainsString('echo 1', $out);
        $this->assertStringContainsString('if($ready)', $out);
    }

    public function testCompileEscapedEchosLeavesBareIdentifierExpressionUnchanged(): void
    {
        $this->assertSame('{{name}}', $this->compiler->compileEscapedEchosPublic('{{name}}'));
    }

    public function testCompileFileWritesCompiledOutputAndReportsReadWriteFailures(): void
    {
        $source = $this->tempDir . '/source.sword';
        $compiled = $this->tempDir . '/compiled.phtml';

        $filesystem = $this->createMock(FilesystemInterface::class);
        $filesystem->expects($this->once())
            ->method('mkdir')
            ->with(dirname($compiled));
        $filesystem->expects($this->once())
            ->method('read')
            ->with($source)
            ->willReturn('Hello {{ $name }}');
        $filesystem->expects($this->once())
            ->method('write')
            ->with($compiled, 'Hello <?= e($name); ?>');

        $pathAlias = $this->createStub(PathAliasInterface::class);
        $pathAlias->method('resolve')->willReturnCallback(
            static fn (string $path): string => match ($path) {
                '@source' => $source,
                '@compiled' => $compiled,
                default => $path,
            }
        );

        $this->compiler
            ->withFilesystem($filesystem)
            ->withPathAlias($pathAlias)
            ->compileFile('@source', '@compiled');

        $filesystem = $this->createMock(FilesystemInterface::class);
        $filesystem->expects($this->once())->method('mkdir')->with(dirname($compiled));
        $filesystem->expects($this->once())
            ->method('read')
            ->with($source)
            ->willThrowException(FileNotFoundException::of('missing'));
        $filesystem->expects($this->never())->method('write');

        $this->compiler
            ->withFilesystem($filesystem)
            ->withPathAlias($pathAlias);

        $this->expectException(TemplateCompilationException::class);
        $this->expectExceptionMessage('Failed to read template: ' . $source);
        $this->compiler->compileFile('@source', '@compiled');
    }

    public function testCompileFileReportsWriteFailures(): void
    {
        $source = $this->tempDir . '/source.sword';
        $compiled = $this->tempDir . '/compiled.phtml';

        $filesystem = $this->createMock(FilesystemInterface::class);
        $filesystem->expects($this->once())
            ->method('mkdir')
            ->with(dirname($compiled));
        $filesystem->expects($this->once())
            ->method('read')
            ->with($source)
            ->willReturn('Hello');
        $filesystem->expects($this->once())
            ->method('write')
            ->with($compiled, 'Hello')
            ->willThrowException(RuntimeException::of('write failed'));

        $pathAlias = $this->createStub(PathAliasInterface::class);
        $pathAlias->method('resolve')->willReturnCallback(
            static fn (string $path): string => match ($path) {
                '@source' => $source,
                '@compiled' => $compiled,
                default => $path,
            }
        );

        $this->compiler
            ->withFilesystem($filesystem)
            ->withPathAlias($pathAlias);

        $this->expectException(TemplateCompilationException::class);
        $this->expectExceptionMessage('Failed to write template: ' . $compiled);
        $this->compiler->compileFile('@source', '@compiled');
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
