<?php

declare(strict_types=1);

namespace Switon\Rendering\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use Switon\Rendering\Engine\Php;
use Switon\Rendering\Frames;

use function file_put_contents;
use function is_file;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

class PhpTest extends TestCase
{
    protected string $tempFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempFile = tempnam(sys_get_temp_dir(), 'php_engine_test_');
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempFile)) {
            unlink($this->tempFile);
        }
        parent::tearDown();
    }

    public function testRenderWithSimpleContent(): void
    {
        file_put_contents($this->tempFile, 'Hello World');

        $engine = new Php();

        ob_start();
        $engine->render($this->tempFile, Frames::of());
        $output = ob_get_clean();

        $this->assertSame('Hello World', $output);
    }

    public function testRenderWithVariables(): void
    {
        file_put_contents($this->tempFile, 'Hello <?= $name ?>');

        $engine = new Php();
        $frames = Frames::of(['name' => 'John']);

        ob_start();
        $engine->render($this->tempFile, $frames);
        $output = ob_get_clean();

        $this->assertSame('Hello John', $output);
    }

    public function testRenderWithMultipleVariables(): void
    {
        file_put_contents($this->tempFile, '<?= $greeting ?> <?= $name ?>!');

        $engine = new Php();
        $frames = Frames::of(['greeting' => 'Hello', 'name' => 'World']);

        ob_start();
        $engine->render($this->tempFile, $frames);
        $output = ob_get_clean();

        $this->assertSame('Hello World!', $output);
    }

    public function testRenderWithArrayVariables(): void
    {
        file_put_contents($this->tempFile, '<?= $items[0] ?> and <?= $items[1] ?>');

        $engine = new Php();
        $frames = Frames::of(['items' => ['apple', 'banana']]);

        ob_start();
        $engine->render($this->tempFile, $frames);
        $output = ob_get_clean();

        $this->assertSame('apple and banana', $output);
    }

    public function testRenderWithComplexPhpCode(): void
    {
        file_put_contents($this->tempFile, '<?php foreach ($items as $item): ?><?= $item ?> <?php endforeach; ?>');

        $engine = new Php();
        $frames = Frames::of(['items' => ['a', 'b', 'c']]);

        ob_start();
        $engine->render($this->tempFile, $frames);
        $output = ob_get_clean();

        $this->assertSame('a b c ', $output);
    }

    public function testRenderWithUndefinedVariable(): void
    {
        file_put_contents($this->tempFile, 'Hello <?= $undefined ?? "default" ?>');

        $engine = new Php();

        ob_start();
        $engine->render($this->tempFile, Frames::of());
        $output = ob_get_clean();

        $this->assertSame('Hello default', $output);
    }
}
